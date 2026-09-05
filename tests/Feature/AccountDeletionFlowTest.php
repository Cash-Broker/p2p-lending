<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Jobs\SendPasswordResetEmail;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AccountDeletionCancelledNotification;
use App\Notifications\AccountDeletionCompletedNotification;
use App\Notifications\AccountDeletionDisownedAdminNotification;
use App\Notifications\AccountDeletionRequestedNotification;
use App\Notifications\AccountDeletionScheduledNotification;
use App\Services\AccountDeletionService;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * SEC-22 (owner 2026-09-03): self-service account deletion = e-mail
 * confirmation + waiting period + «не съм аз» + scheduled finalisation.
 */
class AccountDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investor(array $walletOverrides = []): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now(), 'password' => bcrypt(self::PASSWORD)]);
        $wallet = $user->wallet()->create();
        if ($walletOverrides) {
            $wallet->forceFill($walletOverrides)->save();
        }

        return $user;
    }

    private function service(): AccountDeletionService
    {
        return app(AccountDeletionService::class);
    }

    /** POST the request and capture the two signed links from the mail. @return array{0: string, 1: string} */
    private function requestLinks(User $user): array
    {
        Notification::fake();
        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => self::PASSWORD])->assertStatus(202);

        $links = [];
        Notification::assertSentTo($user, AccountDeletionRequestedNotification::class, function (AccountDeletionRequestedNotification $n) use (&$links) {
            $links = [$n->confirmUrl, $n->cancelUrl];

            return true;
        });

        return $links;
    }

    public function test_the_request_stamps_the_row_mails_both_links_and_anonymises_nothing(): void
    {
        $user = $this->investor();

        [$confirmUrl, $cancelUrl] = $this->requestLinks($user);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->deletion_requested_at);
        $this->assertNull($fresh->deletion_confirmed_at);
        $this->assertSame('awaiting_confirmation', $fresh->deletionState());
        $this->assertSame($user->email, $fresh->email);
        $this->assertNotNull($fresh->wallet);
        $this->assertStringContainsString('/account/deletion/confirm/', $confirmUrl);
        $this->assertStringContainsString('/account/deletion/cancel/', $cancelUrl);
        $this->assertTrue($this->service()->linkHashMatches($fresh, $this->service()->linkHash($fresh)));

        $this->actingAs($fresh)->getJson('/api/user')->assertOk()->assertJsonPath('deletion.state', 'awaiting_confirmation');
    }

    public function test_the_request_is_refused_with_the_old_messages_when_the_account_is_not_empty(): void
    {
        Notification::fake();
        $user = $this->investor(['available' => '500.00']);

        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertNull($user->fresh()->deletion_requested_at);
        Notification::assertNothingSent();
    }

    public function test_the_confirm_link_schedules_the_closure_and_is_idempotent(): void
    {
        $user = $this->investor();
        [$confirmUrl] = $this->requestLinks($user);
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        // GET is the read-only landing page (mail-link prefetchers must not confirm).
        $this->get($confirmUrl)->assertOk()->assertSee('Потвърди закриването');
        $this->assertNull($user->fresh()->deletion_confirmed_at, 'a GET changes nothing');

        $this->post($confirmUrl)->assertRedirect(config('app.url').'/profile?deletion=confirmed');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->deletion_confirmed_at);
        $this->assertSame('scheduled', $fresh->deletionState());
        $this->assertSame(today()->addDays(7)->toDateString(), $fresh->deletion_scheduled_for->toDateString());
        $this->assertTrue($fresh->deletion_scheduled_for->isStartOfDay(), 'the printed date IS the 04:30 run date');
        Notification::assertSentToTimes($user, AccountDeletionScheduledNotification::class, 1);
        Notification::assertSentToTimes($admin, DatabaseNotification::class, 1); // the admin bell rings once

        $scheduledFor = $fresh->deletion_scheduled_for;
        $this->post($confirmUrl)->assertRedirect(config('app.url').'/profile?deletion=confirmed');
        $this->assertEquals($scheduledFor, $user->fresh()->deletion_scheduled_for);
        Notification::assertSentToTimes($user, AccountDeletionScheduledNotification::class, 1);
    }

    public function test_the_waiting_period_setting_is_honoured_and_clamped(): void
    {
        PlatformSetting::set('account_deletion_waiting_days', 3);
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        $this->assertSame(today()->addDays(3)->toDateString(), $user->fresh()->deletion_scheduled_for->toDateString());

        PlatformSetting::set('account_deletion_waiting_days', 0);
        $other = $this->investor();
        $this->requestLinks($other);
        $this->service()->confirm($other->fresh());
        $this->assertSame(today()->addDays(1)->toDateString(), $other->fresh()->deletion_scheduled_for->toDateString());
    }

    public function test_links_die_after_a_cancel_and_after_a_re_request(): void
    {
        $user = $this->investor();
        [$firstConfirm] = $this->requestLinks($user);

        $this->actingAs($user)->postJson('/api/profile/delete/cancel')->assertOk()->assertJsonPath('user.deletion', null);
        $this->assertNull($user->fresh()->deletion_requested_at);
        $this->get($firstConfirm)->assertRedirect(config('app.url').'/login?deletion=invalid');
        $this->actingAs($user)->postJson('/api/profile/delete/cancel')->assertStatus(422);

        Carbon::setTestNow(now()->addSecond());
        [$secondConfirm] = $this->requestLinks($user);
        $this->assertNotSame($firstConfirm, $secondConfirm);
        $this->get($firstConfirm)->assertRedirect(config('app.url').'/login?deletion=invalid');
        $this->post($secondConfirm)->assertRedirect(config('app.url').'/profile?deletion=confirmed');
        $this->assertSame('scheduled', $user->fresh()->deletionState());
    }

    public function test_a_tampered_signature_is_refused_by_the_signed_middleware(): void
    {
        $user = $this->investor();
        [$confirmUrl] = $this->requestLinks($user);

        $this->get($confirmUrl.'x')->assertStatus(403);
        $this->assertNull($user->fresh()->deletion_confirmed_at);
    }

    public function test_the_not_me_link_cancels_purges_tokens_and_alerts_the_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->investor();
        [, $cancelUrl] = $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        $user->createToken('attacker-device');
        $rememberBefore = $user->fresh()->getRememberToken();
        Notification::fake();

        $user->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/attacker', 'public_key' => 'k', 'auth_token' => 't', 'content_encoding' => 'aesgcm']);

        // No login — the person holding the mailbox may be the one locked out of the
        // app. GET only shows the page (a prefetch must not kick anyone out).
        $this->get($cancelUrl)->assertOk()->assertSee('Не съм аз');
        $this->assertNotNull($user->fresh()->deletion_requested_at);
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->post($cancelUrl)->assertRedirect(config('app.url').'/login?deletion=cancelled');

        $fresh = $user->fresh();
        $this->assertNull($fresh->deletion_requested_at);
        $this->assertNull($fresh->deletion_confirmed_at);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(0, $fresh->pushSubscriptions()->count(), 'the attacker\'s device stops receiving pushes');
        $this->assertNotSame($rememberBefore, $fresh->getRememberToken());
        Notification::assertSentTo($user, AccountDeletionCancelledNotification::class, fn ($n) => $n->by === 'link');
        Notification::assertSentTo($admin, AccountDeletionDisownedAdminNotification::class);
        Notification::assertSentTo($admin, DatabaseNotification::class); // admin bell

        // The link is single-use by construction: the hash no longer matches.
        $this->get($cancelUrl)->assertRedirect(config('app.url').'/login?deletion=invalid');
    }

    public function test_an_admin_can_cancel_with_a_reason_and_the_investor_is_told(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        Notification::fake();
        $this->actingAs($admin);

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->callAction('cancel_deletion', data: ['reason' => 'по телефонна молба на клиента'])
            ->assertNotified('Закриването е отменено. Инвеститорът получи имейл.');

        $this->assertNull($user->fresh()->deletion_requested_at);
        Notification::assertSentTo($user, AccountDeletionCancelledNotification::class, fn ($n) => $n->by === 'admin' && str_contains((string) $n->reason, 'телефонна'));

        Livewire::test(ViewUser::class, ['record' => $user->id])->assertActionHidden('cancel_deletion');
    }

    public function test_finalisation_waits_for_the_date_then_anonymises_exactly_like_before_and_is_idempotent(): void
    {
        $user = $this->investor();
        $originalEmail = $user->email;
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        Notification::fake();

        $this->assertSame('skipped', $this->service()->finalize($user->fresh()), 'day 0 — not due');
        $this->assertSame($originalEmail, $user->fresh()->email);

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('finalized', $this->service()->finalize($user->fresh()));

        $fresh = $user->fresh();
        $this->assertStringStartsWith('Изтрит потребител', $fresh->name);
        $this->assertStringStartsWith('deleted_', $fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->wallet);
        $this->assertNotNull($fresh->deletion_finalized_at);
        $this->assertSame('finalized', $fresh->deletionState());
        Notification::assertSentOnDemand(AccountDeletionCompletedNotification::class, fn ($n, $channels, $notifiable) => in_array($originalEmail, (array) $notifiable->routes['mail'], true));

        $this->assertSame('skipped', $this->service()->finalize($fresh), 'a second run is a no-op');
        Notification::assertSentOnDemandTimes(AccountDeletionCompletedNotification::class, 1);
    }

    public function test_money_that_arrives_during_the_window_blocks_finalisation_and_cancels_the_request(): void
    {
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        app(WalletService::class)->credit($user->id, '100.00', Transaction::TYPE_DEPOSIT, 'late wire');
        Notification::fake();

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('blocked', $this->service()->finalize($user->fresh()));

        $fresh = $user->fresh();
        $this->assertSame($user->email, $fresh->email, 'nothing anonymised');
        $this->assertSame('100.00', (string) $fresh->wallet->available);
        $this->assertNull($fresh->deletion_requested_at);
        $this->assertNull($fresh->deletion_finalized_at);
        Notification::assertSentTo($user, AccountDeletionCancelledNotification::class, fn ($n) => $n->by === 'blocked' && str_contains((string) $n->reason, 'баланс'));
    }

    public function test_admin_accounts_are_never_finalised(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->forceFill([
            'deletion_requested_at' => now()->subDays(9),
            'deletion_confirmed_at' => now()->subDays(8),
            'deletion_scheduled_for' => now()->subDay(),
        ])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->finalize($admin);
    }

    public function test_a_request_while_a_closure_is_scheduled_is_refused(): void
    {
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());

        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');
    }

    public function test_a_password_reset_cancels_a_pending_request(): void
    {
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        Notification::fake();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->deletion_requested_at);
        $this->assertNull($fresh->deletion_confirmed_at);
        $this->assertNull($fresh->deletion_scheduled_for);
        Notification::assertSentTo($user, AccountDeletionCancelledNotification::class, fn ($n) => $n->by === 'password_reset');

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('skipped', $this->service()->finalize($fresh));
    }

    public function test_investor_deletion_mails_are_mail_only_and_never_queued(): void
    {
        foreach ([
            AccountDeletionRequestedNotification::class,
            AccountDeletionScheduledNotification::class,
            AccountDeletionCancelledNotification::class,
            AccountDeletionCompletedNotification::class,
        ] as $class) {
            $this->assertFalse((new ReflectionClass($class))->implementsInterface(ShouldQueue::class), "{$class} must be synchronous — the worker may be down");
        }

        $user = $this->investor();
        Notification::fake();
        $this->actingAs($user)->postJson('/api/profile/delete', ['password' => self::PASSWORD])->assertStatus(202);
        Notification::assertSentTo($user, AccountDeletionRequestedNotification::class, fn ($n, array $channels) => $channels === ['mail']);
    }

    public function test_a_closed_account_that_earned_interest_keeps_the_ledger_reconciling(): void
    {
        $user = $this->investor();
        $wallets = app(WalletService::class);
        $wallets->credit($user->id, '100.00', Transaction::TYPE_DEPOSIT, 'seed');
        $wallets->repayInterest($user->id, '5.00', 'interest', 'loan:1:user:'.$user->id);
        $wallets->debit($user->id, '105.00', Transaction::TYPE_WITHDRAWAL, 'cash out', 'withdrawal_request:1');
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('finalized', $this->service()->finalize($user->fresh()));
        $this->assertNull($user->fresh()->wallet);

        // `earned` is a lifetime counter — a closed account must not alarm every night.
        $this->assertSame(0, Artisan::call('ledger:reconcile'));
    }

    public function test_a_closed_account_can_neither_log_in_nor_reset_its_password(): void
    {
        $user = $this->investor();
        $this->requestLinks($user);
        $this->service()->confirm($user->fresh());
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame('finalized', $this->service()->finalize($user->fresh()));

        $closed = $user->fresh();
        $this->assertStringEndsWith('@deleted.invalid', $closed->email);
        $this->assertTrue($closed->isClosed());

        // Even a KNOWN password is refused with the generic failure — no oracle.
        $closed->forceFill(['password' => bcrypt('Known-Password-1!')])->save();
        $this->postJson('/api/login', ['email' => $closed->email, 'password' => 'Known-Password-1!'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // No reset link is ever issued for it…
        Notification::fake();
        (new SendPasswordResetEmail($closed->email))->handle();
        Notification::assertNothingSent();

        // …and a forged token cannot reclaim it.
        $token = Password::createToken($closed);
        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $closed->email,
            'password' => 'Another-Password-1!',
            'password_confirmation' => 'Another-Password-1!',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('Known-Password-1!', $closed->fresh()->password), 'password unchanged');

        // The pre-2026-09 placeholder domain is closed too.
        $legacy = User::factory()->create(['email' => 'deleted_1@removed.p2pinvest.bg']);
        $this->assertTrue($legacy->isClosed());
    }
}
