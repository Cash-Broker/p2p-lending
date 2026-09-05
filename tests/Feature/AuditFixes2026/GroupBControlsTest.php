<?php

namespace Tests\Feature\AuditFixes2026;

use App\Filament\Resources\DepositRequestResource\Pages\ListDepositRequests;
use App\Mail\OpsAlertMail;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\PayoutRunFailedAdminNotification;
use App\Services\DepositService;
use App\Services\ScheduledPayoutService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decisions of 2026-09-03 on the audit's group B (the small, clear ones
 * implemented inline): PAY-31 «без одобрен KYC няма депозит», PAY-24 (fee
 * disclosed = fee charged — see WithdrawalFeeTest) and the ops-alert e-mail.
 */
class GroupBControlsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function investor(string $kycStatus): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => $kycStatus]);
        $user->wallet()->create();

        return $user;
    }

    // ── PAY-31 ──

    public function test_deposit_of_an_unverified_investor_is_refused_at_approval(): void
    {
        Notification::fake();
        $user = $this->investor('submitted');
        $deposits = app(DepositService::class);
        $deposit = $deposits->createRequest($user->id, '250.00');

        try {
            $deposits->approve($deposit->id, $this->admin()->id);
            $this->fail('A deposit must not be credited before the KYC is approved.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC', $e->getMessage());
            $this->assertStringContainsString('submitted', $e->getMessage());
        }

        $this->assertSame('pending', $deposit->fresh()->status, 'the code stays pending — approve again once KYC is approved');
        $this->assertSame('0.00', (string) Wallet::where('user_id', $user->id)->value('available'));
        $this->assertDatabaseMissing('transactions', ['user_id' => $user->id]);
    }

    public function test_deposit_is_credited_once_the_kyc_is_approved(): void
    {
        Notification::fake();
        $user = $this->investor('submitted');
        $deposits = app(DepositService::class);
        $deposit = $deposits->createRequest($user->id, '250.00');

        $user->forceFill(['kyc_status' => 'approved'])->save();
        $deposits->approve($deposit->id, $this->admin()->id);

        $this->assertSame('approved', $deposit->fresh()->status);
        $this->assertSame('250.00', (string) Wallet::where('user_id', $user->id)->value('available'));
    }

    public function test_filament_approve_reports_the_kyc_refusal_as_a_toast(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());
        $user = $this->investor('pending');
        $deposit = app(DepositService::class)->createRequest($user->id, '250.00');

        Livewire::test(ListDepositRequests::class)
            ->callTableAction('approve', $deposit)
            ->assertNotified('Грешка');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('0.00', (string) Wallet::where('user_id', $user->id)->value('available'));
    }

    // ── Ops alerts: «ако някоя операция не мине» ──

    public function test_ops_alert_address_is_configured(): void
    {
        $this->assertNotEmpty(config('app.admin_email'), 'ADMIN_ALERT_EMAIL / app.admin_email must resolve to an address');
    }

    public function test_ledger_mismatch_mails_the_ops_address(): void
    {
        Notification::fake();
        Mail::fake();
        config(['app.admin_email' => 'ops@example.test']);
        $user = $this->investor('approved');
        $deposits = app(DepositService::class);
        $deposit = $deposits->createRequest($user->id, '100.00');
        $deposits->approve($deposit->id, $this->admin()->id);
        Wallet::where('user_id', $user->id)->delete(); // ledger rows left behind → mismatch

        $this->assertSame(1, Artisan::call('ledger:reconcile', ['--notify' => true]));

        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->hasTo('ops@example.test')
            && str_contains($mail->subjectLine, 'Ledger mismatch'));
    }

    public function test_failed_payout_run_mails_the_ops_address_besides_the_admins(): void
    {
        Notification::fake();
        Mail::fake();
        config(['app.admin_email' => 'ops@example.test']);
        $admin = $this->admin();
        $this->mock(ScheduledPayoutService::class, function ($mock) {
            $mock->shouldReceive('runAllAutomatic')->once()->andReturn([
                'loans_processed' => 1, 'loans_failed' => 1, 'failed_loan_ids' => [7],
            ]);
        });

        $this->assertSame(1, Artisan::call('loans:process-payouts'));

        Notification::assertSentTo($admin, PayoutRunFailedAdminNotification::class);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->hasTo('ops@example.test')
            && str_contains($mail->body, '#7'));
    }

    public function test_queue_backlog_mails_the_ops_address(): void
    {
        Mail::fake();
        config(['app.admin_email' => 'ops@example.test']);

        event(new QueueBusy('database', 'default', 300));

        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->hasTo('ops@example.test')
            && str_contains($mail->body, '300'));
    }
}
