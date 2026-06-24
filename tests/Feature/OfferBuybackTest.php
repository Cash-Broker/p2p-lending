<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
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

        $loan->fresh()->transitionTo(Loan::STATUS_ACTIVE);

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
}
