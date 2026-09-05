<?php

namespace Tests\Feature\AuditFixes2026;

use App\Enums\PayoutType;
use App\Models\AmortizationSchedule;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BonusService;
use App\Services\InvestmentService;
use App\Services\Loans\BuybackExecutionService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\Loans\LoanStatusUpdaterService;
use App\Services\PayoutAccrualService;
use App\Services\RepaymentService;
use App\Services\WalletService;
use App\Support\Loans\AccruedInterestLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Audit 2026-09-01, package A1 — the money path.
 *
 * Every test here pins a case where the platform could pay an investor twice,
 * strand their principal behind a terminal loan status, or pay into the future.
 * None of them changes a client decision: they close gaps between the engines
 * that already exist (legacy vs offer, payout vs buyback vs early closure).
 */
class MoneyPathHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Loan, 1: array<int, User>} */
    private function offerLoan(PayoutType $type, string $rate, array $stakes = ['1000.00'], array $loanAttrs = []): array
    {
        Notification::fake();

        $total = array_reduce($stakes, fn ($carry, $stake) => bcadd($carry, $stake, 2), '0.00');

        $loan = Loan::factory()->published()->create(array_merge([
            'amount' => $total, 'investable_amount' => $total, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ], $loanAttrs));
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function forceLate(Loan $loan): void
    {
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);
        $loan->forceFill(['became_late_at' => now()->subDays(70)])->save();
    }

    // ── PAY-25: the legacy engine must never touch an offer-based loan ──

    public function test_legacy_repayment_refuses_offer_based_loan(): void
    {
        [$loan, [$investor]] = $this->offerLoan(PayoutType::Amortizing, '12.00');

        // A borrower-side plan CAN coexist with offer investments (back-dated
        // listings); posting one of its rows used to pay the investor again.
        $row = AmortizationSchedule::create([
            'loan_id' => $loan->id, 'due_date' => now()->toDateString(),
            'principal' => '80.00', 'interest' => '10.00', 'total' => '90.00', 'status' => 'pending',
        ]);

        $before = $investor->wallet->fresh();

        try {
            app(RepaymentService::class)->processRepayment($loan->id, $row->id);
            $this->fail('An offer-based loan must not be paid through the legacy repayment engine.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('offer-based', $e->getMessage());
        }

        $after = $investor->wallet->fresh();
        $this->assertSame((string) $before->invested, (string) $after->invested);
        $this->assertSame((string) $before->available, (string) $after->available);
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertDatabaseMissing('transactions', ['user_id' => $investor->id, 'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL]);
    }

    // ── PAY-28: recovery may not close an offer loan while investors are unpaid ──

    public function test_recovery_keeps_offer_loan_active_while_investor_rows_are_pending(): void
    {
        [$loan] = $this->offerLoan(PayoutType::Amortizing, '12.00');

        // Borrower-side plan: was late, now fully paid → the legacy tiebreaker
        // would say `repaid`. The investors' own rows are still pending.
        $becameLate = now()->subDays(40);
        AmortizationSchedule::create([
            'loan_id' => $loan->id, 'due_date' => now()->subDays(50)->toDateString(),
            'principal' => '1000.00', 'interest' => '10.00', 'total' => '1010.00',
            'status' => 'paid', 'became_late_at' => $becameLate, 'paid_at' => now()->subDays(5),
        ]);
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);
        $loan->forceFill(['became_late_at' => $becameLate])->save();

        $result = app(LoanStatusUpdaterService::class)->transitionLoansAfterLateCheck([$loan->id]);

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status, 'investor schedules still pending → active, never repaid');
        $this->assertContains($loan->id, $result['recovered_to_active']);
        $this->assertNotContains($loan->id, $result['recovered_to_repaid']);
        $this->assertTrue(InvestmentSchedule::where('loan_id', $loan->id)->where('status', 'pending')->exists());
    }

    // ── PAY-27: an investment with NO schedule rows is not "fully paid" ──

    public function test_buyback_refuses_investment_without_schedule_rows(): void
    {
        Originator::query()->update(['buyback' => true, 'buyback_coverage' => 'principal_plus_interest']);
        // Two investors: one keeps its plan (so the buyback total is > 0 and the
        // execution reaches the per-investment loop), the other never got one —
        // the pre-2026-08-13 data gap the backfill command exists for.
        [$loan, [$investor]] = $this->offerLoan(PayoutType::Amortizing, '12.00', ['1000.00', '1000.00']);
        $orphanInvestment = $loan->investments()->where('user_id', $investor->id)->first();
        InvestmentSchedule::where('investment_id', $orphanInvestment->id)->delete();
        $this->forceLate($loan);

        try {
            app(BuybackExecutionService::class)->execute($loan->id, $this->admin()->id);
            $this->fail('Buyback must refuse to close a loan around an investment that never got a plan.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no payout schedule rows', $e->getMessage());
        }

        $this->assertSame(Loan::STATUS_LATE, $loan->fresh()->status);
        $this->assertSame('1000.00', (string) $investor->wallet->fresh()->invested, 'principal stays put, not stranded behind bought_back');
    }

    public function test_early_closure_refuses_investment_without_schedule_rows(): void
    {
        [$loan, [$investor]] = $this->offerLoan(PayoutType::Amortizing, '12.00');
        InvestmentSchedule::where('loan_id', $loan->id)->delete();

        try {
            app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);
            $this->fail('Early closure must refuse an investment without schedule rows.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no payout schedule rows', $e->getMessage());
        }

        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame('1000.00', (string) $investor->wallet->fresh()->invested);
    }

    // ── PAY-29: no payouts into the future ──

    public function test_payout_command_refuses_a_future_as_of_date(): void
    {
        [$loan] = $this->offerLoan(PayoutType::Amortizing, '12.00', ['1000.00'], ['payout_mode' => Loan::PAYOUT_MODE_AUTOMATIC]);

        $exit = Artisan::call('loans:process-payouts', ['--asof' => now()->addYears(3)->toDateString()]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->where('status', 'paid')->count(), 'nothing may be released for a future date');
        $this->assertStringContainsString('future', Artisan::output());
    }

    // ── PAY-12: two partial closures of a capitalized position price against the same net accrued ──

    public function test_second_partial_closure_of_capitalized_position_reconciles(): void
    {
        [$loan, [$investor]] = $this->offerLoan(PayoutType::Capitalized, '20.00');
        $investment = $loan->investments()->first();

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));

        Carbon::setTestNow(Carbon::now()->addDays(185));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '400.00');
        Carbon::setTestNow();

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(215));

        Carbon::setTestNow(Carbon::now()->addDays(215));
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '240.00');
        Carbon::setTestNow();

        // The single investor's `accrued` bucket must equal the ledger net for
        // this investment — the calculator saw the first closure's release.
        $this->assertSame(
            AccruedInterestLedger::netFor($loan->id, $investment->id),
            (string) $investor->wallet->fresh()->accrued,
        );
        $this->assertSame('360.00', (string) InvestmentSchedule::where('investment_id', $investment->id)
            ->whereIn('status', ['pending', 'late'])->sum('principal'));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    // ── PAY-46: a position emptied by a near-full partial closure is `closed`, not zero-`pending` ──

    public function test_near_full_partial_closure_closes_emptied_rows_and_does_not_count_as_received(): void
    {
        [$loan, [$a, $b]] = $this->offerLoan(PayoutType::InterestOnly, '16.00', ['1000.00', '1000.00']);
        $investmentA = $loan->investments()->where('user_id', $a->id)->first();
        $investmentB = $loan->investments()->where('user_id', $b->id)->first();

        Carbon::setTestNow(Carbon::now()->addDays(10));
        // 1 999,99 of 2 000,00: Hamilton hands one investor their ENTIRE outstanding.
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '1999.99');
        Carbon::setTestNow();

        $sum = fn ($rows) => $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');
        $emptied = InvestmentSchedule::where('investment_id', $investmentA->id)->get();
        $kept = InvestmentSchedule::where('investment_id', $investmentB->id)->get();
        [$emptied, $kept] = bccomp($sum($emptied), '0', 2) === 0 ? [$emptied, $kept] : [$kept, $emptied];

        $this->assertSame('0.00', $sum($emptied));
        $this->assertSame(0, $emptied->where('status', 'pending')->count(), 'zero rows must be closed, never left pending');
        $this->assertSame($emptied->count(), $emptied->where('status', InvestmentSchedule::STATUS_CLOSED)->count());
        $this->assertSame('0.01', $sum($kept->whereIn('status', ['pending', 'late'])));

        // The nightly run over the whole term must not turn them into "received" installments…
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));
        $this->assertSame(0, InvestmentSchedule::where('loan_id', $loan->id)->where('status', 'paid')->where('total', '=', 0)->count());

        // …and the conditional-bonus rule counts nothing for the emptied investor.
        $emptiedUserId = $emptied->first()->investment->user_id;
        $this->assertSame('0.00', app(BonusService::class)->qualifiedInvestedAmount($emptiedUserId, now()->subYear(), 3));

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    // ── PAY-35: full closure leaves nothing behind in `accrued` ──

    public function test_full_closure_of_capitalized_position_writes_off_residual_accrued(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        [$loan, [$investor]] = $this->offerLoan(PayoutType::Capitalized, '20.00');

        // Engine milestone ≈ day 26 → one month (16,66 €) sits in `accrued` by day 28.
        $asOf = Carbon::parse('2026-02-12 10:00:00');
        app(PayoutAccrualService::class)->processLoan($loan->id, $asOf);
        $this->assertSame('16.66', (string) $investor->wallet->fresh()->accrued);

        // The closure owes 27 days at 30/360 = 15,00 € — 1,66 € of engine
        // accrual was never earned under the rule Reni chose.
        Carbon::setTestNow($asOf);
        app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id);

        $wallet = $investor->wallet->fresh();
        $this->assertSame('0.00', (string) $wallet->accrued, 'nothing may stay parked in accrued on a repaid loan');
        $this->assertSame('0.00', (string) $wallet->invested);
        $this->assertSame(Loan::STATUS_REPAID, $loan->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $investor->id,
            'type' => Transaction::TYPE_INTEREST_ACCRUAL_REVERSED,
            'amount' => '1.66',
        ]);
        $this->assertSame('15.00', (string) Transaction::where('user_id', $investor->id)
            ->where('type', Transaction::TYPE_INTEREST_RELEASED)->sum('amount'));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    // ── Review 2026-09-03: the write-off follows the POSITION, not the loan flag ──

    public function test_near_full_partial_closure_writes_off_residual_accrued_of_the_emptied_capitalized_position(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        [$loan, [$a, $b]] = $this->offerLoan(PayoutType::Capitalized, '20.00', ['1000.00', '1000.00']);

        $asOf = Carbon::parse('2026-02-12 10:00:00');
        app(PayoutAccrualService::class)->processLoan($loan->id, $asOf);
        $this->assertSame('16.66', (string) $a->wallet->fresh()->accrued);

        // 1 999,99 of 2 000,00: one position is emptied while the loan stays partial.
        Carbon::setTestNow($asOf);
        $result = app(EarlyClosureExecutionService::class)->execute($loan->id, $this->admin()->id, '1999.99');
        Carbon::setTestNow();

        $emptied = collect([$a, $b])->first(fn (User $u) => bccomp((string) $u->wallet->fresh()->invested, '0', 2) === 0);
        $this->assertNotNull($emptied, 'the Hamilton split hands one investor their entire outstanding');
        $kept = $emptied->is($a) ? $b : $a;

        $this->assertSame('0.00', (string) $emptied->wallet->fresh()->accrued, 'an emptied position may not keep a phantom accrued balance');
        $this->assertDatabaseHas('transactions', [
            'user_id' => $emptied->id,
            'type' => Transaction::TYPE_INTEREST_ACCRUAL_REVERSED,
            'amount' => '1.66',
        ]);
        $this->assertSame(1, bccomp((string) $kept->wallet->fresh()->invested, '0', 2), 'the other position stays open');
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->fresh()->status);
        $this->assertSame('1.66', (string) $result['closure']->accrued_written_off, 'the closure row carries what was written off');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }
}
