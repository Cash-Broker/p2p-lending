<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Originator;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\Loans\BuybackExecutionService;
use App\Services\OfferProjectionService;
use App\Services\PayoutAccrualService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Offer-based buyback (boss: enable for the 3 offers, and NEVER pay an investor
 * twice). A loan auto-paid partway and then bought back must leave each investor
 * made whole EXACTLY once, with the capitalized `accrued` bucket netted.
 */
class OfferBuybackTest extends TestCase
{
    use RefreshDatabase;

    private function offerLoan(PayoutType $type, string $rate): array
    {
        Notification::fake();

        $originator = Originator::factory()->create([
            'buyback' => true,
            'buyback_coverage' => 'principal_plus_interest',
        ]);

        $loan = Loan::factory()->published()->create([
            'originator_id' => $originator->id,
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        $loan->offers()->where('payout_type', $type)->update(['interest_rate' => $rate]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', $type)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        // Пълното финансиране вече активира само (2026-08-13) — това остава
        // само за случаите, в които кредитът е докаран до `funded` ръчно.
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->fresh(), $user];
    }

    private function forceLate(Loan $loan): void
    {
        $loan->fresh()->transitionTo(Loan::STATUS_LATE);
        $loan->forceFill(['became_late_at' => now()->subDays(70)])->save();
    }

    public function test_capitalized_buyback_after_accrual_pays_full_interest_once(): void
    {
        [$loan, $user] = $this->offerLoan(PayoutType::Capitalized, '20.00');
        $admin = User::factory()->create();

        // Auto-accrue ~6 months: interest locked in `accrued`, nothing released.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));
        $this->assertGreaterThan(0, (float) $user->wallet->fresh()->accrued);

        $this->forceLate($loan);
        app(BuybackExecutionService::class)->execute($loan->id, $admin->id);

        $finalInterest = (new OfferProjectionService)->summary('1000.00', '20.00', 12, PayoutType::Capitalized)['total_interest'];

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->invested, 'capital fully returned');
        $this->assertSame('0.00', $wallet->accrued, 'accrued netted, not double-counted');
        $this->assertSame($finalInterest, $wallet->earned, 'investor receives the covered interest exactly once');
        $this->assertSame(bcadd('2000.00', $finalInterest, 2), $wallet->available); // 2000 −1000 +1000 +interest

        $this->assertSame('bought_back', $loan->fresh()->status);
        $this->assertSame(0, $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_amortizing_buyback_after_partial_payout_is_not_double_paid(): void
    {
        [$loan, $user] = $this->offerLoan(PayoutType::Amortizing, '12.00');
        $admin = User::factory()->create();

        // Auto-pay the first few months to `available`.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(95));
        $paidEarly = $user->wallet->fresh()->earned;
        $this->assertGreaterThan(0, (float) $paidEarly);

        $this->forceLate($loan);
        app(BuybackExecutionService::class)->execute($loan->id, $admin->id);

        // Investor ends made whole for the FULL plan exactly once: all principal
        // back, total interest == the plan's interest (early payouts + buyback
        // remainder), nothing paid twice.
        $totalInterest = (new OfferProjectionService)->summary('1000.00', '12.00', 12, PayoutType::Amortizing)['total_interest'];

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->invested);
        $this->assertSame('0.00', $wallet->accrued);
        $this->assertSame($totalInterest, $wallet->earned, 'total interest == plan interest (no double-pay)');
        $this->assertSame(bcadd('2000.00', $totalInterest, 2), $wallet->available);

        $this->assertSame(0, $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_capitalized_principal_only_buyback_pays_principal_and_reverses_accrued(): void
    {
        // Regression (audit 2026-07-02): under principal_only coverage the
        // locked accrual used to be RELEASED to the investor anyway — interest
        // the originator never funded. It must be written off instead.
        [$loan, $user] = $this->offerLoan(PayoutType::Capitalized, '20.00');
        Originator::where('id', $loan->originator_id)->update(['buyback_coverage' => 'principal_only']);
        $admin = User::factory()->create();

        // ~6 months of accrual locked in `accrued`.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));
        $accrued = $user->wallet->fresh()->accrued;
        $this->assertGreaterThan(0, (float) $accrued);

        $this->forceLate($loan);
        app(BuybackExecutionService::class)->execute($loan->id, $admin->id);

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->invested, 'capital fully returned');
        $this->assertSame('0.00', $wallet->accrued, 'uncovered accrual written off');
        $this->assertSame('0.00', $wallet->earned, 'principal-only: no interest income');
        $this->assertSame('2000.00', $wallet->available, '2000 − 1000 invested + 1000 principal, zero interest');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => Transaction::TYPE_INTEREST_ACCRUAL_REVERSED,
            'amount' => $accrued,
        ]);

        $event = LoanEvent::where('loan_id', $loan->id)
            ->where('event_type', LoanEvent::TYPE_BUYBACK_COMPLETED)
            ->latest('id')
            ->first();
        $this->assertSame('0.00', $event->metadata['total_interest']);
        $this->assertSame($accrued, $event->metadata['accrued_reversed']);

        $this->assertSame('bought_back', $loan->fresh()->status);
        $this->assertSame(0, $loan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_principal_plus_interest_coverage_still_releases_accrued(): void
    {
        // Guard: the principal_only fix must not change the default coverage —
        // the accrual still counts toward the covered interest, paid once.
        [$loan, $user] = $this->offerLoan(PayoutType::Capitalized, '20.00');
        $admin = User::factory()->create();

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));
        $this->forceLate($loan);
        app(BuybackExecutionService::class)->execute($loan->id, $admin->id);

        $finalInterest = (new OfferProjectionService)->summary('1000.00', '20.00', 12, PayoutType::Capitalized)['total_interest'];
        $wallet = $user->wallet->fresh();
        $this->assertSame($finalInterest, $wallet->earned);
        $this->assertSame('0.00', $wallet->accrued);
        $this->assertSame(0, Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_INTEREST_ACCRUAL_REVERSED)->count(),
            'nothing is reversed under principal_plus_interest');
    }
}
