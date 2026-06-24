<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AmortizationService;
use App\Services\InvestmentService;
use App\Services\ScheduledPayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — the trigger. ScheduledPayoutService dispatches each loan to the
 * right engine (offer → accrual, legacy → amortization posting); runAllAutomatic
 * processes only AUTOMATIC-mode loans; the cron command wraps it.
 */
class ScheduledPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function activeOfferLoan(string $payoutMode = Loan::PAYOUT_MODE_MANUAL): array
    {
        Notification::fake();

        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
            'payout_mode' => $payoutMode,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '2000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', PayoutType::Amortizing)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '1000.00', (string) Str::uuid(), $offerId);

        $loan->refresh();
        $loan->transitionTo(Loan::STATUS_ACTIVE);

        return [$loan->refresh(), $user];
    }

    private function activeLegacyLoan(): array
    {
        Notification::fake();

        $loan = Loan::factory()->active()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 1000,
            'interest_rate' => '12.00', 'term_months' => 6,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        // Ledger-consistent funding: deposit then invest (available → invested).
        $ws = app(WalletService::class);
        $ws->credit($user->id, '1000.00', Transaction::TYPE_DEPOSIT, 'seed');
        $ws->invest($user->id, '1000.00', 'invest in legacy loan', 'investment:legacy');
        Investment::factory()->create(['user_id' => $user->id, 'loan_id' => $loan->id, 'amount' => '1000.00']);

        app(AmortizationService::class)->generateSchedule($loan);

        return [$loan->refresh(), $user];
    }

    public function test_dispatch_runs_accrual_engine_for_offer_loans(): void
    {
        [$loan, $user] = $this->activeOfferLoan();

        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));

        $this->assertSame('offer', $result['type']);
        $this->assertSame('0.00', $user->wallet->fresh()->invested, 'offer principal fully returned at term');
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_dispatch_posts_due_amortization_for_legacy_loans(): void
    {
        [$loan, $user] = $this->activeLegacyLoan();

        $result = app(ScheduledPayoutService::class)->runForLoan($loan, now()->addDays(400));

        $this->assertSame('legacy', $result['type']);
        $this->assertSame(6, $result['posted_count']); // all 6 installments posted
        $this->assertSame('0.00', $user->wallet->fresh()->invested);
        $this->assertSame(0, $loan->amortizationSchedules()->whereIn('status', ['pending', 'late'])->count());
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_run_all_automatic_processes_only_automatic_loans(): void
    {
        [$autoLoan] = $this->activeOfferLoan(Loan::PAYOUT_MODE_AUTOMATIC);
        [$manualLoan] = $this->activeOfferLoan(Loan::PAYOUT_MODE_MANUAL);

        $result = app(ScheduledPayoutService::class)->runAllAutomatic(now()->addDays(400));

        $this->assertSame(1, $result['loans_processed']);
        $this->assertSame(0, $result['loans_failed']);

        // Automatic loan fully paid; manual loan untouched (waits for the button).
        $this->assertSame(0, $autoLoan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());
        $this->assertGreaterThan(0, $manualLoan->investmentSchedules()->whereIn('status', ['pending', 'late'])->count());
    }

    public function test_command_runs_and_reports(): void
    {
        $this->activeOfferLoan(Loan::PAYOUT_MODE_AUTOMATIC);

        $this->assertSame(0, Artisan::call('loans:process-payouts', ['--asof' => now()->addDays(400)->toDateString()]));
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }
}
