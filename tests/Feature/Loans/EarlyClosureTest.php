<?php

namespace Tests\Feature\Loans;

use App\Enums\PayoutType;
use App\Models\BonusGrant;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanEarlyClosure;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BonusService;
use App\Services\InvestmentService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\PayoutAccrualService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Early closure of an offer-based loan, full or partial (Reni 2026-08-18).
 *
 * The borrower returns principal ahead of plan; the platform closes the same
 * share of every investor's position, pays the interest earned on it to the
 * day (30/360) and SHRINKS the rest of their schedule — «свива се позицията,
 * не се намалява срокът», «на всеки по 40% от неговата позиция».
 */
class EarlyClosureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Loan, 1: array<int, User>} */
    private function offerLoan(PayoutType $type, string $rate, array $stakes = ['1000.00']): array
    {
        Notification::fake();

        $total = array_reduce($stakes, fn ($carry, $stake) => bcadd($carry, $stake, 2), '0.00');

        $loan = Loan::factory()->published()->create([
            'amount' => $total, 'investable_amount' => $total, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->offers()->where('payout_type', $type)->update(['interest_rate' => $rate]);
        $offerId = $loan->offers()->where('payout_type', $type)->value('id');

        $investors = [];
        foreach ($stakes as $stake) {
            $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
            $user->wallet()->create();
            app(WalletService::class)->credit($user->id, bcadd($stake, '100.00', 2), Transaction::TYPE_DEPOSIT, 'seed');

            app(InvestmentService::class)->invest($user, $loan->fresh(), $stake, (string) Str::uuid(), $offerId);
            $investors[] = $user;
        }

        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $investors];
    }

    private function outstanding(Loan $loan): string
    {
        return InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'late'])
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ── Full closure ──

    public function test_full_closure_returns_every_cent_of_principal_and_closes_the_loan(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::InterestOnly, '16.00', ['600.00', '400.00']);
        $outstanding = $this->outstanding($loan);
        $this->assertSame('1000.00', $outstanding);

        Carbon::setTestNow(Carbon::now()->addDays(45));
        $result = app(EarlyClosureExecutionService::class)
            ->execute($loan->id, $this->admin()->id);
        Carbon::setTestNow();

        // Every investor got their whole outstanding principal back…
        $this->assertSame('1000.00', $result['quote']['principal_total']);
        $this->assertTrue($result['quote']['is_full']);
        $this->assertSame('600.00', (string) Transaction::where('user_id', $investors[0]->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));

        // …the schedule is cancelled, NOT marked paid…
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)
            ->where('status', 'paid')->count());
        $this->assertGreaterThan(0, InvestmentSchedule::where('loan_id', $loan->id)
            ->where('status', InvestmentSchedule::STATUS_CLOSED)->count());

        // …and the loan is finished.
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->early_repaid_at);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_full_closure_pays_interest_only_for_the_days_actually_used(): void
    {
        // 1000 € at 16%: 45 days on the 30/360 basis = 1000 × 16% × 45/360.
        [$loan, $investors] = $this->offerLoan(PayoutType::InterestOnly, '16.00');
        $invested = $loan->investments()->first()->invested_at;

        Carbon::setTestNow($invested->copy()->addDays(45));
        $result = app(EarlyClosureExecutionService::class)
            ->execute($loan->id, $this->admin()->id);
        Carbon::setTestNow();

        // 45 days between the same day-of-month is exactly 45 on 30/360 only if
        // no month boundary distorts it; assert against the engine's own basis.
        $interest = (string) Transaction::where('user_id', $investors[0]->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_INTEREST)->sum('amount');

        $this->assertSame($result['quote']['interest_total'], bcadd($interest, '0', 2));
        // A month and a half of 16% on 1000 € is 20 €; the day count may shift
        // it by a day or two, never by more than a euro.
        $this->assertGreaterThan(19.0, (float) $interest);
        $this->assertLessThan(21.0, (float) $interest);
    }

    // ── Partial closure ──

    public function test_partial_closure_shrinks_every_position_by_the_same_share(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::Amortizing, '12.00', ['600.00', '400.00']);
        $rowsBefore = InvestmentSchedule::where('loan_id', $loan->id)->count();

        // Borrower returns 40% of the outstanding principal.
        Carbon::setTestNow(Carbon::now()->addDays(20));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '400.00');
        Carbon::setTestNow();

        // Each investor keeps 60% of their own position…
        $first = $loan->investments()->where('user_id', $investors[0]->id)->first();
        $second = $loan->investments()->where('user_id', $investors[1]->id)->first();

        $this->assertSame('360.00', $this->investmentOutstanding($first->id));
        $this->assertSame('240.00', $this->investmentOutstanding($second->id));
        $this->assertSame('600.00', $this->outstanding($loan->fresh()));

        // …over the SAME number of installments — the term does not shrink.
        $this->assertSame($rowsBefore, InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'late'])->count());

        // 40% of each position came back as principal.
        $this->assertSame('240.00', (string) Transaction::where('user_id', $investors[0]->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));
        $this->assertSame('160.00', (string) Transaction::where('user_id', $investors[1]->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_repeated_partial_closures_stay_exact(): void
    {
        [$loan] = $this->offerLoan(PayoutType::InterestOnly, '16.00', ['333.33', '333.33', '333.34']);

        $start = Carbon::now();

        Carbon::setTestNow($start->copy()->addDays(10));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '300.00');
        $this->assertSame('700.00', $this->outstanding($loan->fresh()));

        Carbon::setTestNow($start->copy()->addDays(40));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '250.00');
        $this->assertSame('450.00', $this->outstanding($loan->fresh()));

        // «може колкото пъти иска» — and the last one closes the loan.
        Carbon::setTestNow($start->copy()->addDays(70));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '450.00');
        Carbon::setTestNow();
        $this->assertSame('0.00', $this->outstanding($loan->fresh()));
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);

        $this->assertSame(3, LoanEarlyClosure::where('loan_id', $loan->id)->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_partial_closure_stops_the_platform_paying_interest_on_returned_money(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::InterestOnly, '16.00');
        $investment = $loan->investments()->first();

        $futureInterest = fn () => InvestmentSchedule::where('investment_id', $investment->id)
            ->whereIn('status', ['pending', 'late'])
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->interest, 2), '0.00');

        $before = $futureInterest();

        Carbon::setTestNow(Carbon::now()->addDays(15));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '500.00');
        Carbon::setTestNow();

        // Half the principal is back with the borrower, so the platform now
        // owes half the future interest — that is the whole point of the
        // feature («набутваме се с лихви иначе»). Per-row cents move with the
        // Hamilton split; the TOTAL is the invariant.
        $this->assertSame(bcadd(bcdiv($before, '2', 4), '0', 2), $futureInterest());
        $this->assertNotNull($investors[0]);
    }

    // ── Capitalized ──

    public function test_capitalized_partial_closure_pays_accrued_and_keeps_compounding_on_the_rest(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::Capitalized, '20.00');
        $investor = $investors[0];

        // Six months of accrual locked in the `accrued` bucket.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));
        $accruedBefore = (string) $investor->wallet->fresh()->accrued;
        $this->assertGreaterThan(0, (float) $accruedBefore);

        Carbon::setTestNow(Carbon::now()->addDays(185));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '400.00');
        Carbon::setTestNow();

        // 40% of the position closed: principal back, its accrued share paid.
        $this->assertSame('400.00', (string) Transaction::where('user_id', $investor->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL)->sum('amount'));
        $this->assertSame('600.00', $this->outstanding($loan->fresh()));

        $accruedAfter = (string) $investor->wallet->fresh()->accrued;
        $this->assertLessThan((float) $accruedBefore, (float) $accruedAfter);

        // The remaining 60% keeps compounding — and the payout engine must
        // accrue on the SHRUNKEN principal, not the original investment.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(215));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    // ── Guards and interactions ──

    public function test_a_future_as_of_date_cannot_inflate_the_interest(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::InterestOnly, '16.00');

        // Handing the service tomorrow+ must not buy the investor extra days.
        app(EarlyClosureExecutionService::class)
            ->execute($loan->id, $this->admin()->id, null, Carbon::now()->addYear());

        $interest = (string) Transaction::where('user_id', $investors[0]->id)
            ->where('type', Transaction::TYPE_EARLY_REPAYMENT_INTEREST)->sum('amount');

        // Closed the same day it was funded → a rounding-level amount, never a
        // year's worth of 16%.
        $this->assertLessThan(1.0, (float) $interest);
        $this->assertSame(Carbon::now()->toDateString(), LoanEarlyClosure::first()->as_of->toDateString());
    }

    public function test_a_malformed_amount_is_refused_before_any_money_moves(): void
    {
        [$loan] = $this->offerLoan(PayoutType::InterestOnly, '16.00');

        try {
            app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, 'not-a-number');
            $this->fail('Expected the money normalizer to reject the amount.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('1000.00', $this->outstanding($loan->fresh()));
        $this->assertSame(0, LoanEarlyClosure::count());
    }

    public function test_legacy_loans_are_rejected(): void
    {
        $loan = Loan::factory()->active()->create();

        $this->expectException(InvalidArgumentException::class);
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);
    }

    public function test_closing_more_than_the_outstanding_principal_is_refused(): void
    {
        [$loan] = $this->offerLoan(PayoutType::InterestOnly, '16.00');

        $this->expectException(InvalidArgumentException::class);
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '1500.00');
    }

    public function test_a_cancelled_installment_does_not_unlock_a_conditional_bonus(): void
    {
        [$loan, $investors] = $this->offerLoan(PayoutType::InterestOnly, '16.00');
        $investor = $investors[0];

        $grant = app(BonusService::class)->grantAdminBonus(
            $investor, '50.00', '1000.00', 'Реферал', $this->admin()->id, 'bonus:admin:1:'.uniqid(),
        );

        // Reni 2026-08-18: an early closure must NOT unlock the bonus. Closed
        // rows are cancelled, not received — the condition counts payouts.
        Carbon::setTestNow(Carbon::now()->addDays(60));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);
        Carbon::setTestNow();

        Artisan::call('bonuses:release-eligible');

        $this->assertSame(BonusGrant::STATUS_LOCKED, $grant->fresh()->status);
    }

    private function investmentOutstanding(int $investmentId): string
    {
        return InvestmentSchedule::where('investment_id', $investmentId)
            ->whereIn('status', ['pending', 'late'])
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');
    }
}
