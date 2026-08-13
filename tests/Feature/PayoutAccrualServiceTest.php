<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentService;
use App\Services\OfferProjectionService;
use App\Services\PayoutAccrualService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The scheduled-accrual payout engine (boss feature). Amortizing/interest-only
 * release on schedule; capitalized accrues monthly into the locked bucket
 * (текущо салдо grows) and releases at maturity. Money math reconciles.
 */
class PayoutAccrualServiceTest extends TestCase
{
    use RefreshDatabase;

    private function splitInvestorLoan(): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 3000, 'investable_amount' => 3000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        // Seed via a real deposit so the ledger stays reconcilable.
        app(WalletService::class)->credit($user->id, '5000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        $service = app(InvestmentService::class);
        foreach (PayoutType::cases() as $type) {
            $offerId = $loan->offers()->where('payout_type', $type)->value('id');
            $service->invest($user, $loan->fresh(), '1000.00', "inv-{$type->value}", $offerId);
        }

        $loan->refresh();
        // Пълното финансиране вече активира само (2026-08-13) — това остава
        // само за случаите, в които кредитът е докаран до `funded` ръчно.
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->refresh(), $user];
    }

    private function capitalizedInvestorLoan(string $rate = '20.00'): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);
        // Drive the capitalized offer to the test rate.
        $loan->offers()->where('payout_type', PayoutType::Capitalized)->update(['interest_rate' => $rate]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed deposit');

        $offerId = $loan->offers()->where('payout_type', PayoutType::Capitalized)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', 'inv-cap', $offerId);

        $loan->refresh();
        // Пълното финансиране вече активира само (2026-08-13) — това остава
        // само за случаите, в които кредитът е докаран до `funded` ръчно.
        if ($loan->fresh()->status !== Loan::STATUS_ACTIVE) {
            $loan->transitionTo(Loan::STATUS_ACTIVE);
        }

        return [$loan->refresh(), $user];
    }

    public function test_full_term_run_matches_total_promised_payout(): void
    {
        [$loan, $user] = $this->splitInvestorLoan();

        $proj = new OfferProjectionService;
        $expectedEarned = '0.00';
        foreach ([[PayoutType::Amortizing, '12.00'], [PayoutType::InterestOnly, '16.00'], [PayoutType::Capitalized, '20.00']] as [$type, $rate]) {
            $expectedEarned = bcadd($expectedEarned, $proj->summary('1000.00', $rate, 12, $type)['total_interest'], 2);
        }

        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(400));

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->invested, 'all principal returned at full term');
        $this->assertSame('0.00', $wallet->accrued, 'capitalized accrual fully released at maturity');
        $this->assertSame($expectedEarned, $wallet->earned, 'earned == total interest across all three offers');
        $this->assertSame(bcadd('5000.00', $expectedEarned, 2), $wallet->available);

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_capitalized_grows_current_balance_before_maturity_without_releasing(): void
    {
        [$loan, $user] = $this->capitalizedInvestorLoan('20.00');

        // ~6 months in.
        app(PayoutAccrualService::class)->processLoan($loan->id, now()->addDays(185));

        $wallet = $user->wallet->fresh();

        // Capital still deployed, nothing paid out yet…
        $this->assertSame('1000.00', $wallet->invested);
        $this->assertSame('1000.00', $wallet->available); // 2000 start − 1000 invested, no release
        $this->assertSame('0.00', $wallet->earned);

        // …but the locked profit has been accrued → текущо салдо grew.
        $this->assertGreaterThan(0, (float) $wallet->accrued, 'capitalized interest must accrue pre-maturity');
        $finalInterest = (new OfferProjectionService)->summary('1000.00', '20.00', 12, PayoutType::Capitalized)['total_interest'];
        $this->assertLessThan((float) $finalInterest, (float) $wallet->accrued, 'pre-maturity accrual < full maturity interest');
        $this->assertSame(bcadd('1000.00', (string) $wallet->accrued, 2), $wallet->currentBalance());

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_capitalized_releases_exact_interest_at_maturity(): void
    {
        [$loan, $user] = $this->capitalizedInvestorLoan('20.00');

        // Accrue partway, then run to maturity — must release EXACTLY the
        // schedule's interest, no compounding drift.
        $svc = app(PayoutAccrualService::class);
        $svc->processLoan($loan->id, now()->addDays(185));
        $svc->processLoan($loan->id, now()->addDays(400));

        $finalInterest = (new OfferProjectionService)->summary('1000.00', '20.00', 12, PayoutType::Capitalized)['total_interest'];

        $wallet = $user->wallet->fresh();
        $this->assertSame('0.00', $wallet->invested);
        $this->assertSame('0.00', $wallet->accrued);
        $this->assertSame($finalInterest, $wallet->earned);
        $this->assertSame(bcadd('2000.00', $finalInterest, 2), $wallet->available); // 2000 −1000 +1000 +interest

        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_rerun_same_date_is_idempotent(): void
    {
        [$loan, $user] = $this->splitInvestorLoan();
        $svc = app(PayoutAccrualService::class);

        $svc->processLoan($loan->id, now()->addDays(95));
        $snapshot = $user->wallet->fresh()->only(['available', 'invested', 'accrued', 'earned']);

        $svc->processLoan($loan->id, now()->addDays(95)); // same date again
        $after = $user->wallet->fresh()->only(['available', 'invested', 'accrued', 'earned']);

        $this->assertSame($snapshot, $after, 'a second run for the same date must not move any money');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }
}
