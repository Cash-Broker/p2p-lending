<?php

namespace Tests\Feature;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InvestorWeeklyEarningsNotification;
use App\Services\InvestmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The investor weekly earnings bulletin (Reni 2026-08-13): «тази седмица
 * спечели X сума», by email, Monday cron. Pins recipient selection, the
 * weekly sum, the kill switch, and the no-spam skip rule.
 */
class InvestorWeeklyEarningsTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedInvestor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    public function test_investor_with_weekly_interest_gets_the_email_with_the_correct_sum(): void
    {
        Notification::fake();

        $user = $this->verifiedInvestor();
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 12.50, 'created_at' => now()->subDays(2),
        ]);
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_INTEREST_RELEASED,
            'amount' => 7.49, 'created_at' => now()->subDays(6),
        ]);
        // Outside the window — must NOT count.
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 99, 'created_at' => now()->subDays(10),
        ]);
        // Principal is not earnings — must NOT count.
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
            'amount' => 50, 'created_at' => now()->subDays(2),
        ]);

        $this->artisan('investors:weekly-earnings')->assertSuccessful();

        Notification::assertSentTo($user, InvestorWeeklyEarningsNotification::class,
            fn (InvestorWeeklyEarningsNotification $n) => $n->weeklyInterest === '19.99');
    }

    public function test_investor_with_running_accrual_but_no_payouts_yet_still_gets_the_email(): void
    {
        Notification::fake();

        // Fresh partial investment in a funding loan — nothing paid out yet,
        // but the «текуща печалба» pace is running, so the bulletin goes out.
        $loan = Loan::factory()->published()->create([
            'amount' => 1000, 'investable_amount' => 1000, 'funded_amount' => 0,
            'interest_rate' => '12.00', 'term_months' => 12,
        ]);

        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();
        app(WalletService::class)->credit($user->id, '1000.00', Transaction::TYPE_DEPOSIT, 'seed');

        $offerId = $loan->offers()->where('payout_type', PayoutType::InterestOnly)->value('id');
        app(InvestmentService::class)->invest($user, $loan->fresh(), '500.00', 'inv-weekly', $offerId);

        $this->artisan('investors:weekly-earnings')->assertSuccessful();

        Notification::assertSentTo($user, InvestorWeeklyEarningsNotification::class);
    }

    public function test_investor_with_nothing_running_and_nothing_received_is_skipped(): void
    {
        Notification::fake();

        $user = $this->verifiedInvestor();

        $this->artisan('investors:weekly-earnings')->assertSuccessful();

        Notification::assertNotSentTo($user, InvestorWeeklyEarningsNotification::class);
    }

    public function test_kill_switch_stops_all_sending(): void
    {
        Notification::fake();

        PlatformSetting::set('investor_weekly_email_enabled', false);

        $user = $this->verifiedInvestor();
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 10, 'created_at' => now()->subDay(),
        ]);

        $this->artisan('investors:weekly-earnings')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_dry_run_sends_nothing(): void
    {
        Notification::fake();

        $user = $this->verifiedInvestor();
        Transaction::factory()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => 10, 'created_at' => now()->subDay(),
        ]);

        $this->artisan('investors:weekly-earnings --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_unverified_and_admin_accounts_never_receive_the_bulletin(): void
    {
        Notification::fake();

        $unverified = User::factory()->unverified()->create();
        $unverified->wallet()->create();
        $admin = User::factory()->admin()->create();
        $admin->wallet()->create();

        foreach ([$unverified, $admin] as $user) {
            Transaction::factory()->create([
                'user_id' => $user->id, 'type' => Transaction::TYPE_REPAYMENT_INTEREST,
                'amount' => 10, 'created_at' => now()->subDay(),
            ]);
        }

        $this->artisan('investors:weekly-earnings')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
