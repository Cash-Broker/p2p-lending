<?php

namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\User;
use App\Notifications\InvestorRegisteredAdminNotification;
use App\Notifications\KycSubmittedAdminNotification;
use App\Notifications\WithdrawalRequestedAdminNotification;
use App\Services\TelegramService;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Event-driven admin alerts (client request 2026-08-07: "когато има
 * какво, без час"): the admin gets an EMAIL the moment an actionable
 * item appears — KYC submitted, withdrawal requested — plus a
 * synchronous (notifyNow) Filament bell. Only to admins, never to
 * investors; a notification failure never fails the user's own action
 * and one failed dispatch never suppresses the remaining ones.
 *
 * (Deposits deliberately have NO event alert: the wire lands at the
 * bank, off-platform — there is no in-app moment to hook. Buyback has
 * its own immediate email from the 03:45 cron.)
 */
class AdminActionItemAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kycPayload(): array
    {
        return [
            'document_front' => UploadedFile::fake()->image('id-front.jpg', 800, 600),
            'document_back' => UploadedFile::fake()->image('id-back.jpg', 800, 600),
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 600, 600),
            'biometric_consent' => '1',
        ];
    }

    private function submitKyc(User $user): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/profile/kyc', $this->kycPayload());
    }

    /** Dispatcher mock where BOTH send (queued mail) and sendNow (bell) throw. */
    private function bindThrowingDispatcher(): void
    {
        $this->app->instance(
            Dispatcher::class,
            Mockery::mock(Dispatcher::class, function ($mock) {
                $mock->shouldReceive('send')->andThrow(new RuntimeException('mail down'));
                $mock->shouldReceive('sendNow')->andThrow(new RuntimeException('bell down'));
            }),
        );
    }

    // ── KYC submitted: transition guard ──

    public function test_kyc_submission_emails_admins_only(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = $this->makeAdmin();
        $otherInvestor = User::factory()->create(['email_verified_at' => now()]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        Notification::assertSentTo(
            $admin,
            KycSubmittedAdminNotification::class,
            fn (KycSubmittedAdminNotification $n) => $n->applicantId === $user->id
                && $n->applicantName === $user->name
                && $n->applicantEmail === $user->email,
        );
        Notification::assertNotSentTo($otherInvestor, KycSubmittedAdminNotification::class);
        Notification::assertNotSentTo($user, KycSubmittedAdminNotification::class);
        Notification::assertNotSentTo($otherInvestor, DatabaseNotification::class);
        Notification::assertNotSentTo($user, DatabaseNotification::class);
    }

    public function test_kyc_email_fans_out_to_every_admin(): void
    {
        Storage::fake('local');
        Notification::fake();
        $adminOne = $this->makeAdmin();
        $adminTwo = $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        Notification::assertSentTo([$adminOne, $adminTwo], KycSubmittedAdminNotification::class);
        Notification::assertSentTimes(KycSubmittedAdminNotification::class, 2);
        Notification::assertSentTimes(DatabaseNotification::class, 2);
    }

    public function test_reupload_while_awaiting_review_does_not_email_again_but_still_bells(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'submitted']);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        // No second email page — but the bell must still fire so the
        // reviewer knows the documents changed under an open review.
        Notification::assertNotSentTo($admin, KycSubmittedAdminNotification::class);
        Notification::assertSentTo($admin, DatabaseNotification::class);
    }

    public function test_reupload_during_admin_review_does_not_email_again(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'in_review']);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        Notification::assertNotSentTo($admin, KycSubmittedAdminNotification::class);
    }

    public function test_resubmission_after_rejection_emails_again(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now(), 'kyc_status' => 'rejected']);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        // rejected → submitted is a NEW review task.
        Notification::assertSentTo($admin, KycSubmittedAdminNotification::class);
    }

    public function test_approved_user_is_rejected_before_any_notification(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->makeAdmin();
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->submitKyc($user)
            ->assertStatus(422)
            ->assertJson(['message' => 'KYC already approved.']);

        $this->assertSame('approved', $user->fresh()->kyc_status);
        Notification::assertNothingSent();
    }

    /**
     * The guard must read the DB, not the request-hydrated model: a second
     * process whose model was hydrated before the first one committed
     * (multi-second HEIC conversion runs before the lock) must not re-email.
     */
    public function test_stale_model_double_submit_emails_exactly_once(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->submitKyc($user)->assertOk();

        // Simulate the second process's stale hydration: in-memory status
        // predates the first commit. The controller's refresh() under the
        // lock must override it.
        $user->kyc_status = 'pending';
        $this->submitKyc($user)->assertOk();

        Notification::assertSentTimes(KycSubmittedAdminNotification::class, 1);
    }

    public function test_kyc_submission_survives_notification_failure(): void
    {
        Storage::fake('local');
        $this->makeAdmin();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->wallet()->create();

        $this->bindThrowingDispatcher();

        $this->submitKyc($user)->assertOk();
        $this->assertSame('submitted', $user->fresh()->kyc_status);
        // The Art. 9 consent evidence committed together with the flip.
        $this->assertDatabaseHas('consent_records', [
            'user_id' => $user->id,
            'type' => ConsentRecord::TYPE_BIOMETRIC,
        ]);
    }

    // ── Withdrawal requested ──

    public function test_withdrawal_request_emails_and_bells_admins_only(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $bystander = User::factory()->create(['email_verified_at' => now()]);
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => '250.00',
            'iban' => 'BG80BNBG96611020345678',
        ]);

        $response->assertStatus(201);
        Notification::assertSentTo(
            $admin,
            WithdrawalRequestedAdminNotification::class,
            function (WithdrawalRequestedAdminNotification $n) use ($user, $admin) {
                $mail = $n->toMail($admin);
                $html = (string) $mail->render();

                return $n->amount === '250.00'
                    && $n->investorName === $user->name
                    // PII hygiene pinned on the REAL instance: the IBAN the
                    // user submitted must never appear in the outbound email.
                    && ! str_contains($mail->subject, 'BG80BNBG96611020345678')
                    && ! str_contains($html, 'BG80BNBG96611020345678')
                    && ! str_contains(json_encode(get_object_vars($n)), 'BG80BNBG96611020345678');
            },
        );
        // The in-panel bell — and its recipient boundary.
        Notification::assertSentTo($admin, DatabaseNotification::class);
        Notification::assertNotSentTo($user, WithdrawalRequestedAdminNotification::class);
        Notification::assertNotSentTo($user, DatabaseNotification::class);
        Notification::assertNotSentTo($bystander, WithdrawalRequestedAdminNotification::class);
        Notification::assertNotSentTo($bystander, DatabaseNotification::class);
    }

    public function test_withdrawal_request_survives_notification_failure(): void
    {
        $this->makeAdmin();
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $this->bindThrowingDispatcher();

        $response = $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => '250.00',
            'iban' => 'BG80BNBG96611020345678',
        ]);

        // Money movement already committed — the request must succeed and
        // the reservation must hold despite the notification failure.
        $response->assertStatus(201);
        $this->assertDatabaseHas('withdrawal_requests', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $this->assertSame('250.00', $user->wallet->fresh()->reserved);
    }

    /**
     * Per-dispatch isolation: a failing BELL (sendNow) for every admin must
     * not suppress the EMAIL leg — with two admins, both still get the
     * email. Pins the $safeNotify per-dispatch try/catch.
     */
    public function test_bell_failure_does_not_suppress_emails_for_any_admin(): void
    {
        $adminOne = $this->makeAdmin();
        $adminTwo = $this->makeAdmin();
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $fake = new NotificationFake;
        $this->app->instance(Dispatcher::class, new class($fake) implements Dispatcher
        {
            public function __construct(private NotificationFake $fake) {}

            public function send($notifiables, $notification)
            {
                $this->fake->send($notifiables, $notification);
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('bell down');
            }
        });

        $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => '250.00',
            'iban' => 'BG80BNBG96611020345678',
        ])->assertStatus(201);

        $fake->assertSentTo([$adminOne, $adminTwo], WithdrawalRequestedAdminNotification::class);
        $fake->assertSentTimes(WithdrawalRequestedAdminNotification::class, 2);
    }

    /**
     * The bell row must exist WITHOUT a queue worker: notifyNow bypasses
     * ShouldQueue (Filament v5's DatabaseNotification is queued by default)
     * and inserts synchronously. Queue::fake() would swallow a queued bell.
     */
    public function test_withdrawal_bell_row_exists_without_queue_worker(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->kycApproved()->create(['email_verified_at' => now()]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => '250.00',
            'iban' => 'BG80BNBG96611020345678',
        ])->assertStatus(201);

        $this->assertSame(1, $admin->notifications()->count(),
            'Bell row must be inserted synchronously, independent of the queue');
    }

    // ── Investor registered (Reni 2026-08-20) ──

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Нов Инвеститор',
            'email' => 'nov@example.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ], $overrides);
    }

    public function test_registration_alerts_admins_only(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $bystander = User::factory()->create(['email_verified_at' => now()]);

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        $registered = User::where('email', 'nov@example.com')->firstOrFail();

        Notification::assertSentTo(
            $admin,
            InvestorRegisteredAdminNotification::class,
            fn (InvestorRegisteredAdminNotification $n) => $n->investorId === $registered->id
                && $n->investorName === 'Нов Инвеститор'
                && $n->investorEmail === 'nov@example.com'
                && $n->consolidatedCount === 1,
        );
        Notification::assertSentTo($admin, DatabaseNotification::class);

        // Recipient boundary: neither the newcomer nor an unrelated investor
        // learns that someone registered.
        Notification::assertNotSentTo($bystander, InvestorRegisteredAdminNotification::class);
        Notification::assertNotSentTo($bystander, DatabaseNotification::class);
        Notification::assertNotSentTo($registered, InvestorRegisteredAdminNotification::class);
        Notification::assertNotSentTo($registered, DatabaseNotification::class);
    }

    public function test_registration_alert_fans_out_to_every_admin(): void
    {
        Notification::fake();
        $adminOne = $this->makeAdmin();
        $adminTwo = $this->makeAdmin();

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        Notification::assertSentTo([$adminOne, $adminTwo], InvestorRegisteredAdminNotification::class);
        Notification::assertSentTimes(InvestorRegisteredAdminNotification::class, 2);
        Notification::assertSentTimes(DatabaseNotification::class, 2);
    }

    public function test_legal_entity_registration_is_labelled_as_such(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->postJson('/api/register', [
            'account_type' => 'legal_entity',
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'email' => 'firma@example.com',
            'phone' => '+359 88 123 4567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
            'legal_name' => 'ВАМА АСЕТ',
            'eik' => '201035515',
        ])->assertStatus(201);

        Notification::assertSentTo(
            $admin,
            InvestorRegisteredAdminNotification::class,
            function (InvestorRegisteredAdminNotification $n) use ($admin) {
                return $n->accountType === 'legal_entity'
                    && str_contains((string) $n->toMail($admin)->render(), 'Юридическо лице');
            },
        );
    }

    /**
     * /api/register is the only PUBLIC trigger for an admin alert — a script
     * could otherwise page the inbox once per created account. Past the
     * threshold the hour collapses into one summary and then goes silent,
     * bell included; the Users list stays the complete record.
     */
    public function test_registration_burst_consolidates_then_goes_quiet(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        User::factory()->count(10)->create();

        $this->postJson('/api/register', $this->registrationPayload(['email' => 'burst@example.com']))
            ->assertStatus(201);

        Notification::assertSentTo(
            $admin,
            InvestorRegisteredAdminNotification::class,
            function (InvestorRegisteredAdminNotification $n) use ($admin) {
                $mail = $n->toMail($admin);

                // Render the burst branch of the template too — it is the one
                // nobody sees until the day it matters.
                return $n->consolidatedCount === 11
                    && $n->isConsolidated()
                    && str_contains($mail->subject, '11 нови регистрации')
                    && str_contains((string) $mail->render(), '11');
            },
        );

        // Fresh recorder: the next registration in the same hour must be
        // completely silent — no email, no push, no bell.
        Notification::fake();

        $this->postJson('/api/register', $this->registrationPayload(['email' => 'after@example.com']))
            ->assertStatus(201);

        Notification::assertNotSentTo($admin, InvestorRegisteredAdminNotification::class);
        Notification::assertNotSentTo($admin, DatabaseNotification::class);
    }

    /**
     * The crossing is `>=`, not `==` (review 2026-08-20). Two accounts
     * committing between two counts make every observer see 12 or 13, and an
     * equality test would skip the summary entirely — the window would go
     * silent without the one alert that says alerting is suspended.
     */
    public function test_burst_summary_fires_even_when_the_count_jumps_past_the_threshold(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        User::factory()->count(12)->create();

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        Notification::assertSentTo(
            $admin,
            InvestorRegisteredAdminNotification::class,
            fn (InvestorRegisteredAdminNotification $n) => $n->consolidatedCount === 13
                && $n->isConsolidated(),
        );
    }

    /**
     * The summary promises «отделните известия са спрени до края на часа», so
     * the window must stay silent even when the rolling count falls back under
     * the threshold — otherwise individual alerts resume minutes after the
     * summary said they had stopped, and a paced drip re-sends the summary
     * (and its non-silent Telegram ping) over and over.
     */
    public function test_one_summary_per_window_even_after_the_count_falls_back(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $ballast = User::factory()->count(10)->create();

        $this->postJson('/api/register', $this->registrationPayload(['email' => 'first@example.com']))
            ->assertStatus(201);
        Notification::assertSentTimes(InvestorRegisteredAdminNotification::class, 1);

        // The window drains: the ballast ages out of the trailing hour.
        User::whereIn('id', $ballast->pluck('id'))->update(['created_at' => now()->subHours(2)]);

        Notification::fake();
        $this->postJson('/api/register', $this->registrationPayload(['email' => 'second@example.com']))
            ->assertStatus(201);

        Notification::assertNotSentTo($admin, InvestorRegisteredAdminNotification::class);
        Notification::assertNotSentTo($admin, DatabaseNotification::class);
    }

    public function test_registrations_outside_the_window_do_not_consolidate(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        // Well over the threshold, but two hours ago — the window is rolling.
        User::factory()->count(15)->create(['created_at' => now()->subHours(2)]);

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        Notification::assertSentTo(
            $admin,
            InvestorRegisteredAdminNotification::class,
            fn (InvestorRegisteredAdminNotification $n) => $n->consolidatedCount === 1,
        );
    }

    /** An admin account must not page the admins about itself. */
    public function test_admin_account_registration_alerts_nobody(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $newAdmin = User::factory()->admin()->create();

        event(new Registered($newAdmin));

        Notification::assertNotSentTo($admin, InvestorRegisteredAdminNotification::class);
        Notification::assertNotSentTo($admin, DatabaseNotification::class);
    }

    /**
     * The alert is a side effect of registration, never a gate on it: the
     * account and its wallet are already committed when the listener runs.
     */
    public function test_registration_survives_an_exploding_alert(): void
    {
        $this->makeAdmin();
        $this->app->instance(
            TelegramService::class,
            Mockery::mock(TelegramService::class, function ($mock) {
                $mock->shouldReceive('info')->andThrow(new RuntimeException('telegram down'));
                $mock->shouldReceive('high')->andThrow(new RuntimeException('telegram down'));
            }),
        );

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        $registered = User::where('email', 'nov@example.com')->firstOrFail();
        $this->assertDatabaseHas('wallets', ['user_id' => $registered->id]);
        $this->assertDatabaseHas('consent_records', ['user_id' => $registered->id]);
    }

    /**
     * The bell row must exist WITHOUT a queue worker: notifyNow bypasses
     * ShouldQueue (Filament v5's DatabaseNotification is queued by default).
     */
    public function test_registration_bell_row_exists_without_queue_worker(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();

        $this->postJson('/api/register', $this->registrationPayload())->assertStatus(201);

        $this->assertSame(1, $admin->notifications()->count(),
            'Bell row must be inserted synchronously, independent of the queue');
    }

    // ── Injection hardening (2026-08-07 security review) ──

    public function test_markdown_link_in_name_does_not_become_anchor_in_admin_mail(): void
    {
        $admin = $this->makeAdmin();
        $notification = new KycSubmittedAdminNotification(
            applicantId: 42,
            applicantName: '[Отворете заявката](https://evil.example)',
            applicantEmail: 'attacker@example.com',
            accountType: 'individual',
            submittedAt: now(),
        );

        $html = (string) $notification->toMail($admin)->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html,
            'User-controlled markdown must render as inert text (Markdown::withSecuredEncoding)');
    }

    public function test_markdown_link_in_name_does_not_become_anchor_in_registration_mail(): void
    {
        $admin = $this->makeAdmin();
        $notification = new InvestorRegisteredAdminNotification(
            investorId: 42,
            investorName: '[Отворете профила](https://evil.example)',
            investorEmail: 'attacker@example.com',
            accountType: 'individual',
            registeredAt: now(),
        );

        $html = (string) $notification->toMail($admin)->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html,
            'User-controlled markdown must render as inert text (Markdown::withSecuredEncoding)');
    }

    public function test_name_with_control_characters_is_rejected_at_ingress(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => "Иван\n\n# ВНИМАНИЕ: сменен IBAN",
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_html_in_name_is_escaped_in_bell_body(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->kycApproved()->create([
            'email_verified_at' => now(),
            'name' => '<a href="https://evil.example">Иван — виж заявката</a>',
        ]);
        $user->wallet()->create()->forceFill(['available' => '5000.00'])->save();

        $this->actingAs($user)->postJson('/api/withdrawal', [
            'amount' => '250.00',
            'iban' => 'BG80BNBG96611020345678',
        ])->assertStatus(201);

        $body = $admin->notifications()->first()->data['body'];
        $this->assertStringNotContainsString('<a href', $body,
            'Raw user name must be entity-escaped before Filament\'s sanitized-HTML bell rendering');
        $this->assertStringContainsString('&lt;a href', $body);
    }

    // ── Mail rendering (pins the Blade templates) ──

    public function test_kyc_mail_renders_in_bulgarian_with_review_link(): void
    {
        config(['app.name' => 'NotVamaasset']);
        $admin = $this->makeAdmin();
        $notification = new KycSubmittedAdminNotification(
            applicantId: 42,
            applicantName: 'Иван Иванов',
            applicantEmail: 'ivan@example.com',
            accountType: 'individual',
            submittedAt: now(),
        );

        $mail = $notification->toMail($admin);
        $html = $mail->render();

        $this->assertStringContainsString('Нова KYC заявка — Иван Иванов', $mail->subject);
        $this->assertStringContainsString('Физическо лице', $html);
        $this->assertStringContainsString('/admin/users/42', $html);
        $this->assertStringContainsString('екипът на Vamaasset', $html);
        $this->assertStringNotContainsString('екипът на NotVamaasset', $html);
    }

    public function test_withdrawal_mail_renders_without_iban(): void
    {
        config(['app.name' => 'NotVamaasset']);
        $admin = $this->makeAdmin();
        $notification = new WithdrawalRequestedAdminNotification(
            withdrawalId: 7,
            investorName: 'Мария Петрова',
            amount: '1250.50',
            requestedAt: now(),
        );

        $mail = $notification->toMail($admin);
        $html = $mail->render();

        $this->assertStringContainsString('Ново заявено теглене — 1250.50 €', $mail->subject);
        $this->assertStringContainsString('Мария Петрова', $html);
        $this->assertStringContainsString('#7', $html);
        $this->assertStringContainsString('/admin/withdrawal-requests', $html);
        // Template-level pin only — the end-to-end IBAN-absence pin lives in
        // test_withdrawal_request_emails_and_bells_admins_only.
        $this->assertStringNotContainsString('BG80', $html);
        $this->assertStringContainsString('екипът на Vamaasset', $html);
        $this->assertStringNotContainsString('екипът на NotVamaasset', $html);
    }
}
