<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\WithdrawalRequestedAdminNotification;
use App\Notifications\WithdrawalRequestedNotification;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesSavedIbans;
use Tests\TestCase;

/**
 * SEC-01 (owner 2026-09-03): a withdrawal goes only to a saved IBAN the owner
 * confirmed by e-mail, at least 24 h after the confirmation — and the owner is
 * told the moment a withdrawal is requested.
 */
class WithdrawalIbanGateTest extends TestCase
{
    use CreatesSavedIbans, RefreshDatabase;

    private const IBAN = 'BG80BNBG96611020345678';

    private function investor(string $available = '5000.00'): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => $available])->save();

        return $user;
    }

    private function postWithdrawal(User $user, array $payload, array $headers = [])
    {
        Notification::fake();

        return $this->actingAs($user)->postJson('/api/withdrawal', $payload, $headers);
    }

    public function test_a_raw_iban_in_the_request_is_prohibited(): void
    {
        $user = $this->investor();

        $this->postWithdrawal($user, ['amount' => 100, 'iban' => self::IBAN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban', 'saved_iban_id']);

        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
        $this->assertSame(0, WithdrawalRequest::count());
        Notification::assertNothingSent();
    }

    public function test_an_unconfirmed_iban_is_refused_and_nothing_is_reserved(): void
    {
        $user = $this->investor();
        $iban = $this->unconfirmedIban($user);

        $response = $this->postWithdrawal($user, ['amount' => 100, 'saved_iban_id' => $iban->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('saved_iban_id');
        $this->assertStringContainsString('не е потвърден', $response->json('errors.saved_iban_id.0'));
        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
        $this->assertSame(0, WithdrawalRequest::count());
    }

    public function test_an_iban_confirmed_less_than_24_hours_ago_is_refused(): void
    {
        $user = $this->investor();
        $iban = $this->confirmedIban($user, self::IBAN, now()->subHours(23));

        $response = $this->postWithdrawal($user, ['amount' => 100, 'saved_iban_id' => $iban->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('saved_iban_id');
        $this->assertStringContainsString('възможно от', $response->json('errors.saved_iban_id.0'));
        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
    }

    public function test_an_iban_confirmed_24_hours_ago_is_accepted_and_the_request_records_it(): void
    {
        $user = $this->investor();
        $iban = $this->confirmedIban($user, self::IBAN, now()->subHours(24));

        $this->postWithdrawal($user, ['amount' => 1000, 'saved_iban_id' => $iban->id])->assertStatus(201);

        $request = WithdrawalRequest::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(self::IBAN, $request->iban);
        $this->assertEquals($iban->id, $request->saved_iban_id);
        $wallet = $user->wallet->fresh();
        $this->assertSame('4000.00', (string) $wallet->available);
        $this->assertSame('1000.00', (string) $wallet->reserved);
    }

    public function test_a_cooldown_of_zero_hours_allows_a_withdrawal_right_after_confirmation(): void
    {
        PlatformSetting::set('withdrawal_new_iban_cooldown_hours', 0);
        $user = $this->investor();
        $iban = $this->confirmedIban($user, self::IBAN, now()->subMinute());

        $this->postWithdrawal($user, ['amount' => 100, 'saved_iban_id' => $iban->id])->assertStatus(201);
    }

    public function test_another_investors_confirmed_iban_is_refused(): void
    {
        $user = $this->investor();
        $other = $this->investor();
        $iban = $this->confirmedIban($other);

        $this->postWithdrawal($user, ['amount' => 100, 'saved_iban_id' => $iban->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('saved_iban_id');
        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
    }

    public function test_an_iban_deleted_before_the_service_lock_is_refused_with_nothing_reserved(): void
    {
        $user = $this->investor();
        $iban = $this->confirmedIban($user);
        DB::table('saved_ibans')->where('id', $iban->id)->delete();

        try {
            app(WithdrawalService::class)->createRequest($user->id, '100.00', $iban);
            $this->fail('a destination that vanished must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('saved_iban_id', $e->errors());
        }

        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
        $this->assertSame(0, WithdrawalRequest::count());
    }

    public function test_the_investor_is_notified_at_request_time_but_not_on_an_idempotent_replay(): void
    {
        $user = $this->investor();
        $iban = $this->confirmedIban($user);
        $key = (string) Str::uuid();
        $payload = ['amount' => 100, 'saved_iban_id' => $iban->id];

        Notification::fake();
        $this->actingAs($user)->postJson('/api/withdrawal', $payload, ['X-Idempotency-Key' => $key])->assertStatus(201);
        $this->actingAs($user)->postJson('/api/withdrawal', $payload, ['X-Idempotency-Key' => $key])->assertStatus(201);

        Notification::assertSentToTimes($user, WithdrawalRequestedNotification::class, 1);
        Notification::assertSentTo($user, WithdrawalRequestedNotification::class, function (WithdrawalRequestedNotification $n, array $channels) use ($user) {
            $mail = $n->toMail($user)->render();
            $push = $n->toWebPush($user)->toArray();

            return in_array(QueuedWebPushChannel::class, $channels, true)
                && str_contains($mail, '****5678')
                && ! str_contains($mail, self::IBAN)
                && str_contains($mail, 'сменете паролата')
                && ! str_contains(json_encode($push), '5678')
                && ! str_contains(json_encode($push), $user->name);
        });
        $this->assertSame(1, WithdrawalRequest::where('user_id', $user->id)->count());
    }

    public function test_admins_still_get_their_alert_and_the_approve_path_is_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = $this->investor();
        $iban = $this->confirmedIban($user);

        $this->postWithdrawal($user, ['amount' => 100, 'saved_iban_id' => $iban->id])->assertStatus(201);
        Notification::assertSentTo($admin, WithdrawalRequestedAdminNotification::class);

        $request = WithdrawalRequest::where('user_id', $user->id)->firstOrFail();
        app(WithdrawalService::class)->approve($request->id, $admin->id);

        $tx = Transaction::where('reference', "withdrawal_request:{$request->id}")->where('type', Transaction::TYPE_WITHDRAWAL)->firstOrFail();
        $this->assertSame('100.00', (string) $tx->amount);
        $this->assertStringContainsString('****5678', $tx->description);
        $this->assertSame('0.00', (string) $user->wallet->fresh()->reserved);
    }

    public function test_a_legacy_iban_from_before_the_feature_takes_a_withdrawal_once_it_is_a_day_old(): void
    {
        // Deploy-day path: every live investor's IBAN has no token — it counts as
        // confirmed from created_at and must simply keep working (review 2026-09-05).
        $user = $this->investor();
        $iban = $this->legacyIban($user);
        DB::table('saved_ibans')->where('id', $iban->id)->update(['created_at' => now()->subDays(2)]);

        $this->postWithdrawal($user, ['amount' => '100.00', 'saved_iban_id' => $iban->id], ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201);
        $this->assertSame('100.00', (string) $user->wallet->fresh()->reserved);
        $this->assertDatabaseHas('withdrawal_requests', ['user_id' => $user->id, 'saved_iban_id' => $iban->id]);

        // A legacy row created an hour ago is inside the cooldown like any other.
        $other = $this->investor();
        $young = $this->legacyIban($other, 'BG18RZBB91550123456789');
        $this->postWithdrawal($other, ['amount' => '100.00', 'saved_iban_id' => $young->id], ['X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('saved_iban_id');
        $this->assertSame('0.00', (string) $other->wallet->fresh()->reserved);
    }
}
