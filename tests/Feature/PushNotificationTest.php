<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebPushNotification;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\DepositApprovedNotification;
use App\Notifications\InvestmentMadeAdminNotification;
use App\Notifications\InvestorPayoutDigestNotification;
use App\Notifications\KycStatusNotification;
use App\Notifications\PushEnabledNotification;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Web Push (2026-08-17): subscription endpoints + channel wiring + the
 * morning payout digest. The actual push HTTP delivery is the package's
 * concern — these tests pin OUR routing, scoping and aggregation.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Delivery jobs are faked class-wide: without it the sync queue would run
     * DeliverWebPushNotification inline and fire REAL HTTPS requests at
     * fcm.googleapis.com (slow, flaky, and it deleted rows when the test env's
     * empty VAPID config made the crypto throw). Tests that care about
     * delivery assert on the queued job instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function investor(): User
    {
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        return $user;
    }

    private function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1'): void
    {
        $keys = $this->validKeys();
        $user->registerPushSubscription($endpoint, $keys['p256dh'], $keys['auth']);
    }

    /**
     * REAL browser-shaped crypto keys: p256dh is an actual point ON the P-256
     * curve (a fabricated 0x04 + 64 arbitrary bytes passes a length check but
     * explodes in the push crypto — review 2026-08-17), auth a 16-byte secret.
     *
     * @return array{p256dh: string, auth: string}
     */
    private function validKeys(): array
    {
        $b64url = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        // A genuine point on P-256 — the user-agent public key from the
        // RFC 8291 §5 web-push example. Generating one here is not portable:
        // this dev box's OpenSSL cannot create EC keys (the same reason
        // `artisan webpush:vapid` fails on it).
        $p256dh = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

        return [
            'p256dh' => $p256dh,
            'auth' => $b64url(random_bytes(16)),
        ];
    }

    // ── Subscription endpoints ──

    public function test_authenticated_user_can_register_a_push_subscription(): void
    {
        $user = $this->investor();

        $this->actingAs($user)->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => $this->validKeys(),
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_subscribe_rejects_non_https_endpoints(): void
    {
        $user = $this->investor();

        $this->actingAs($user)->postJson('/api/push/subscribe', [
            'endpoint' => 'http://insecure.example/x',
            'keys' => $this->validKeys(),
        ])->assertUnprocessable();
    }

    public function test_subscribe_rejects_endpoints_outside_the_known_push_services(): void
    {
        // The server later POSTs to whatever is stored — an unrestricted
        // endpoint is an outbound-request primitive (review 2026-08-17).
        $user = $this->investor();

        foreach ([
            'https://attacker.example/collect',
            'https://169.254.169.254/latest/meta-data/',
            'https://fcm.googleapis.com.evil.example/fcm/send/x',
        ] as $endpoint) {
            $this->actingAs($user)->postJson('/api/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => $this->validKeys(),
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscribe_accepts_every_supported_browser_push_service(): void
    {
        $user = $this->investor();

        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://web.push.apple.com/abc',
            'https://ABC.notify.windows.com/w/?token=abc',
        ] as $endpoint) {
            $this->actingAs($user)->postJson('/api/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => $this->validKeys(),
            ])->assertCreated();
        }
    }

    public function test_subscribe_rejects_malformed_crypto_keys(): void
    {
        // Garbage keys used to poison the row and make every later send throw
        // — with the sync channel that rolled back admin transactions.
        $user = $this->investor();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/keys-test';

        $b64url = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $cases = [
            ['p256dh' => 'not base64!!', 'auth' => $this->validKeys()['auth']],
            // Valid base64url but the wrong decoded length (3 bytes, not 65).
            ['p256dh' => 'AAAA', 'auth' => $this->validKeys()['auth']],
            ['p256dh' => $this->validKeys()['p256dh'], 'auth' => 'AAAA'],
            // Right length, right 0x04 prefix — but NOT a point on P-256.
            // This is the shape that used to pass and then retry forever.
            ['p256dh' => $b64url("\x04".str_repeat("\x11", 64)), 'auth' => $this->validKeys()['auth']],
        ];

        foreach ($cases as $keys) {
            $this->actingAs($user)->postJson('/api/push/subscribe', [
                'endpoint' => $endpoint,
                'keys' => $keys,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_over_long_endpoint_is_a_422_not_a_database_error(): void
    {
        // The column is string(500): validating at a larger max meant a 500
        // (and a CRITICAL Telegram alert) instead of a clean rejection.
        $user = $this->investor();

        $this->actingAs($user)->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 600),
            'keys' => $this->validKeys(),
        ])->assertUnprocessable();
    }

    public function test_device_registrations_are_capped_per_user(): void
    {
        $user = $this->investor();

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($user)->postJson('/api/push/subscribe', [
                'endpoint' => "https://fcm.googleapis.com/fcm/send/device-{$i}",
                'keys' => $this->validKeys(),
            ])->assertCreated();
        }

        $this->assertDatabaseCount('push_subscriptions', 10);
        // The oldest two were pruned, the newest survive.
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1']);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-12']);
    }

    public function test_guest_cannot_register_a_subscription(): void
    {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/x',
            'keys' => $this->validKeys(),
        ])->assertUnauthorized();
    }

    public function test_unsubscribe_removes_only_the_own_subscription(): void
    {
        $user = $this->investor();
        $other = $this->investor();
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/shared-endpoint');
        $this->subscribe($other, 'https://fcm.googleapis.com/fcm/send/other-device');

        // Attempting to delete ANOTHER user's endpoint is a silent no-op.
        $this->actingAs($user)->deleteJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/other-device',
        ])->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/other-device']);

        // Deleting the own endpoint works.
        $this->actingAs($user)->deleteJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/shared-endpoint',
        ])->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/shared-endpoint']);
    }

    public function test_one_device_serves_both_an_admin_and_an_investor_account(): void
    {
        // Reni's phone (2026-08-17): the admin panel AND her investor profile
        // live in the same browser. Both accounts must keep receiving — the
        // package's global endpoint uniqueness used to make the last login
        // steal the device and silently kill the other stream.
        $investor = $this->investor();
        $admin = User::factory()->admin()->create();
        $phone = 'https://fcm.googleapis.com/fcm/send/renis-phone';

        $this->subscribe($investor, $phone);
        $this->actingAs($admin)->postJson('/api/push/subscribe', [
            'endpoint' => $phone,
            'keys' => $this->validKeys(),
        ])->assertCreated();

        // Two rows for one endpoint — one per account.
        $this->assertSame(2, \DB::table('push_subscriptions')->where('endpoint', $phone)->count());
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $phone, 'subscribable_id' => $admin->id]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $phone, 'subscribable_id' => $investor->id]);

        // Re-registering the SAME account refreshes its row, never duplicates.
        $this->actingAs($admin)->postJson('/api/push/subscribe', [
            'endpoint' => $phone,
            'keys' => $this->validKeys(),
        ])->assertCreated();
        $this->assertSame(2, \DB::table('push_subscriptions')->where('endpoint', $phone)->count());
    }

    public function test_logging_out_one_account_leaves_the_other_accounts_subscription(): void
    {
        // The investor logging out of the SPA must not silence Reni's admin
        // notifications on the same phone.
        $investor = $this->investor();
        $admin = User::factory()->admin()->create();
        $phone = 'https://fcm.googleapis.com/fcm/send/renis-phone';
        $this->subscribe($investor, $phone);
        $this->subscribe($admin, $phone);

        $this->actingAs($investor)->deleteJson('/api/push/subscribe', ['endpoint' => $phone])->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $phone, 'subscribable_id' => $investor->id]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $phone, 'subscribable_id' => $admin->id]);
    }

    // ── Channel wiring ──

    public function test_deposit_approved_notification_targets_webpush_too(): void
    {
        $user = $this->investor();

        $channels = (new DepositApprovedNotification('500.00', 'DEP-TEST0001'))->via($user);

        $this->assertContains(QueuedWebPushChannel::class, $channels);
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_admin_investment_push_carries_no_investor_name(): void
    {
        $admin = User::factory()->admin()->create();
        $notification = new InvestmentMadeAdminNotification(7, 'Иван Тайников', '500.00', 12, 'Анюитет', '12.00');

        $message = $notification->toWebPush($admin);
        $payload = $message->toArray();

        $this->assertStringNotContainsString('Тайников', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Нова инвестиция: 500.00 €', $payload['title']);
    }

    // ── Delivery happens on the QUEUE, never inline ──

    public function test_channel_queues_delivery_instead_of_sending_inline(): void
    {
        Queue::fake();

        $user = $this->investor();
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/dev-a');
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/dev-b');

        $user->notify(new DepositApprovedNotification('500.00', 'DEP-TEST0001'));

        // One job per device, carrying pre-rendered scalars.
        Queue::assertPushed(DeliverWebPushNotification::class, 2);
    }

    public function test_confirmation_push_targets_only_the_new_device_and_only_on_a_real_opt_in(): void
    {
        // «Здравей» (Yordan 2026-08-17): proof of delivery on the device that
        // just enrolled — NOT a fan-out to every device the person owns, and
        // never on the silent ownership re-asserts both UIs perform.
        Queue::fake();

        $user = $this->investor();
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/older-device');

        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/new-device',
            'keys' => $this->validKeys(),
            'confirm' => true,
        ];

        $this->actingAs($user)->postJson('/api/push/subscribe', $payload)->assertCreated();

        $newId = \DB::table('push_subscriptions')
            ->where('endpoint', 'https://fcm.googleapis.com/fcm/send/new-device')->value('id');

        // Exactly one delivery, aimed at the new subscription only.
        Queue::assertPushed(DeliverWebPushNotification::class, 1);
        Queue::assertPushed(
            DeliverWebPushNotification::class,
            fn (DeliverWebPushNotification $job) => (new \ReflectionProperty($job, 'subscriptionId'))
                ->getValue($job) === (int) $newId,
        );

        // Same device again (re-assert, confirm defaults to false) — silence.
        $this->actingAs($user)->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/new-device',
            'keys' => $this->validKeys(),
        ])->assertCreated();
        Queue::assertPushed(DeliverWebPushNotification::class, 1);
    }

    public function test_opting_in_confirms_even_when_the_device_was_already_registered(): void
    {
        // The SPA auto-registers the device on load, so a person's actual
        // click almost always lands on an EXISTING row. Requiring a brand-new
        // row swallowed the «здравей» exactly then (2026-08-17).
        Queue::fake();

        $user = $this->investor();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/already-known';
        $this->subscribe($user, $endpoint);

        $this->actingAs($user)->postJson('/api/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => $this->validKeys(),
            'confirm' => true,
        ])->assertCreated();

        Queue::assertPushed(DeliverWebPushNotification::class, 1);
    }

    public function test_a_second_account_on_the_same_browser_is_confirmed_once_then_stays_quiet(): void
    {
        // Reni's phone: the admin panel enrols silently (permission already
        // granted, no button to press). That FIRST registration deserves one
        // «здравей» — every later page load must be silent (2026-08-17).
        Queue::fake();

        $investor = $this->investor();
        $admin = User::factory()->admin()->create();
        $browser = 'https://fcm.googleapis.com/fcm/send/shared-browser';
        $this->subscribe($investor, $browser);

        $silentRegistration = [
            'endpoint' => $browser,
            'keys' => $this->validKeys(),
            'confirm' => false,
        ];

        $this->actingAs($admin)->postJson('/api/push/subscribe', $silentRegistration)->assertCreated();
        Queue::assertPushed(DeliverWebPushNotification::class, 1);

        // Every subsequent panel load re-asserts the same row — no repeats.
        $this->actingAs($admin)->postJson('/api/push/subscribe', $silentRegistration)->assertCreated();
        $this->actingAs($admin)->postJson('/api/push/subscribe', $silentRegistration)->assertCreated();
        Queue::assertPushed(DeliverWebPushNotification::class, 1);
    }

    public function test_confirmation_push_is_role_aware_and_leaves_no_record(): void
    {
        $admin = User::factory()->admin()->create();
        $investor = $this->investor();

        $adminPayload = (new PushEnabledNotification)->toWebPush($admin)->toArray();
        $investorPayload = (new PushEnabledNotification)->toWebPush($investor)->toArray();

        $this->assertStringContainsString('инвестиции', $adminPayload['body']);
        $this->assertStringContainsString('лихви', $investorPayload['body']);
        // Distinct tags: both accounts may live on ONE device, and a shared
        // tag would make the second confirmation replace the first.
        $this->assertNotSame($adminPayload['tag'], $investorPayload['tag']);
        // Push-only: nothing to store in the bell or by mail.
        $this->assertSame([QueuedWebPushChannel::class], (new PushEnabledNotification)->via($investor));
    }

    public function test_kyc_approval_survives_a_broken_push_subscription(): void
    {
        // Regression (review 2026-08-17): the synchronous channel ran push
        // HTTP INSIDE UserResource::transitionKycStatus's DB transaction, so
        // a malformed key threw and rolled the approval back — after the
        // investor's approval email had already gone out.
        $user = $this->investor();
        $user->forceFill(['kyc_status' => 'submitted'])->save();
        // A row the crypto layer cannot use (valid base64url, wrong length).
        $user->registerPushSubscription('https://fcm.googleapis.com/fcm/send/poisoned', 'AAAA', 'AAAA');

        $user->notify(new KycStatusNotification('approved'));

        // The notification completed; nothing bubbled out to abort a caller.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
    }

    // ── Morning payout digest ──

    public function test_payout_digest_pushes_only_to_subscribed_investors_with_interest(): void
    {
        Notification::fake();

        $walletService = app(WalletService::class);

        $subscribedEarner = $this->investor();
        $this->subscribe($subscribedEarner);
        $unsubscribedEarner = $this->investor();
        $subscribedIdler = $this->investor();
        $this->subscribe($subscribedIdler, 'https://fcm.googleapis.com/fcm/send/device-2');

        // Interest paid by the payout ENGINE (loan:{id}:investment:… refs).
        $walletService->credit($subscribedEarner->id, '13.33', Transaction::TYPE_REPAYMENT_INTEREST, 'Лихва', 'loan:12:investment:5:schedule:1');
        $walletService->credit($subscribedEarner->id, '5.00', Transaction::TYPE_INTEREST_RELEASED, 'Лихва', 'loan:14:investment:6:capitalized');
        $walletService->credit($unsubscribedEarner->id, '9.99', Transaction::TYPE_REPAYMENT_INTEREST, 'Лихва', 'loan:12:investment:9:schedule:2');

        $this->artisan('push:payout-digest')->assertSuccessful();

        Notification::assertSentTo(
            $subscribedEarner,
            InvestorPayoutDigestNotification::class,
            function (InvestorPayoutDigestNotification $notification) use ($subscribedEarner) {
                $payload = $notification->toWebPush($subscribedEarner)->toArray();

                return str_contains($payload['title'], '18.33')
                    && str_contains($payload['body'], '2 кредита');
            },
        );
        Notification::assertNotSentTo($unsubscribedEarner, InvestorPayoutDigestNotification::class);
        Notification::assertNotSentTo($subscribedIdler, InvestorPayoutDigestNotification::class);
    }

    public function test_payout_digest_ignores_old_and_non_interest_transactions(): void
    {
        Notification::fake();

        $user = $this->investor();
        $this->subscribe($user);

        $walletService = app(WalletService::class);
        // Principal return is not «печалба» — must not trigger the digest.
        $walletService->credit($user->id, '100.00', Transaction::TYPE_REPAYMENT_PRINCIPAL, 'Главница', 'loan:12:user:'.$user->id);
        // Old interest outside the 24h window. Inserted directly with a
        // back-dated created_at — transactions are UPDATE-immutable at the
        // DB level (trigger), so post-hoc backdating is impossible by design.
        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'type' => Transaction::TYPE_REPAYMENT_INTEREST,
            'amount' => '7.77',
            'description' => 'Лихва (стара)',
            'reference' => 'loan:12:old:'.Str::uuid(),
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('push:payout-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_payout_digest_skips_interest_that_already_had_its_own_push(): void
    {
        // «числата винаги верни» (review 2026-08-17): legacy repayments,
        // buyback and early repayment each push instantly with their own
        // amount. Counting them here again would announce the same euros
        // twice — only payout-engine references belong in the digest.
        Notification::fake();

        $walletService = app(WalletService::class);
        $user = $this->investor();
        $this->subscribe($user);

        // Legacy repayment — RepaymentReceivedNotification already pushed it.
        $walletService->credit($user->id, '12.34', Transaction::TYPE_REPAYMENT_INTEREST, 'Лихва', 'loan:12:user:'.$user->id);
        // Buyback interest — LoanBoughtBackNotification already pushed it.
        $walletService->credit($user->id, '8.00', Transaction::TYPE_BUYBACK_INTEREST, 'Лихва', 'loan:13:buyback:user:'.$user->id);

        $this->artisan('push:payout-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_payout_digest_respects_the_kill_switch(): void
    {
        Notification::fake();

        PlatformSetting::set('push_payout_digest_enabled', false);

        $user = $this->investor();
        $this->subscribe($user);
        app(WalletService::class)->credit($user->id, '5.00', Transaction::TYPE_REPAYMENT_INTEREST, 'Лихва', 'loan:1:investment:1:schedule:1');

        $this->artisan('push:payout-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
