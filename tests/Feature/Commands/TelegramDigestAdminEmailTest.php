<?php

namespace Tests\Feature\Commands;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\DepositRequest;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\AdminActionItemsNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * telegram:digest — admin action-items EMAIL rules (client request
 * 2026-08-07): the morning digest must email admin accounts when there is
 * pending work (KYC awaiting review, deposits awaiting confirmation,
 * withdrawals awaiting processing, buyback queue), and must stay silent
 * on all-clear mornings. Email only to admins, never to investors, and
 * independent of Telegram being configured.
 */
class TelegramDigestAdminEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '42',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** A loan in late status — informational for the digest, NOT an email trigger. */
    private function makeLateLoan(): Loan
    {
        $orig = Originator::create([
            'name' => 'Digest Test '.uniqid(),
            'description' => 'X',
            'buyback' => true,
        ]);
        $borrower = Borrower::create([
            'full_name' => 'B', 'personal_id' => '0', 'address' => 'A',
            'phone' => '+1', 'income' => '1000',
        ]);
        BorrowerAnonymizedProfile::create([
            'borrower_id' => $borrower->id, 'risk_class' => 'B', 'region' => 'X',
            'loan_purpose' => 'X', 'collateral_type' => '—', 'age_group' => '30-40',
        ]);

        return Loan::create([
            'originator_id' => $orig->id, 'borrower_id' => $borrower->id,
            'amount' => '1000', 'funded_amount' => '1000',
            'interest_rate' => '12', 'interest_rate_annual' => '15',
            'term_months' => 6, 'type' => 'consumer',
            'status' => 'late',
            'became_late_at' => now()->subDays(10),
        ]);
    }

    public function test_email_sent_to_admin_only_when_kyc_pending(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $investor = User::factory()->create();
        User::factory()->create(['kyc_status' => 'submitted']);

        Artisan::call('telegram:digest');

        Notification::assertSentTo(
            $admin,
            AdminActionItemsNotification::class,
            fn (AdminActionItemsNotification $n) => $n->kycPending === 1
                && $n->depositsPending === 0
                && $n->withdrawalsPending === 0
                && $n->buybackQueue === 0,
        );
        Notification::assertNotSentTo($investor, AdminActionItemsNotification::class);
    }

    public function test_funded_pending_deposit_triggers_email_and_needs_attention_title(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['created_at' => now()->subDays(3)]);
        DepositRequest::factory()->create([
            'user_id' => $user->id,
            'amount' => '250.00',
            'status' => 'pending',
        ]);

        Artisan::call('telegram:digest');

        Notification::assertSentTo(
            $admin,
            AdminActionItemsNotification::class,
            fn (AdminActionItemsNotification $n) => $n->depositsPending === 1
                && $n->kycPending === 0
                && $n->withdrawalsPending === 0
                && $n->buybackQueue === 0,
        );
        // A funded pending deposit alone must also flip the Telegram title.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', 'има задачи за обработка'));
    }

    public function test_no_email_when_nothing_actionable(): void
    {
        Notification::fake();
        $this->makeAdmin();

        Artisan::call('telegram:digest');

        Notification::assertNothingSent();
    }

    public function test_unfunded_deposit_codes_do_not_trigger_email(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $user = User::factory()->create(['created_at' => now()->subDays(3)]);
        // Issued reference codes nobody wired against — not actionable.
        DepositRequest::factory()->count(4)->create([
            'user_id' => $user->id,
            'amount' => null,
            'status' => 'pending',
        ]);

        Artisan::call('telegram:digest');

        Notification::assertNothingSent();
    }

    public function test_pending_withdrawal_triggers_email_to_every_admin(): void
    {
        Notification::fake();
        $adminOne = $this->makeAdmin();
        $adminTwo = $this->makeAdmin();
        WithdrawalRequest::factory()->create(['status' => 'pending']);
        // Non-pending rows must not inflate the count.
        WithdrawalRequest::factory()->create(['status' => 'approved']);

        Artisan::call('telegram:digest');

        Notification::assertSentTo(
            [$adminOne, $adminTwo],
            AdminActionItemsNotification::class,
            fn (AdminActionItemsNotification $n) => $n->withdrawalsPending === 1
                && $n->kycPending === 0
                && $n->depositsPending === 0
                && $n->buybackQueue === 0,
        );
        $this->assertStringContainsString('2 admin(s)', Artisan::output());
    }

    public function test_late_loans_alone_do_not_trigger_email(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeLateLoan();

        Artisan::call('telegram:digest');

        // Late/default is loan health, not an admin task — the actionable
        // derivative is the buyback queue (F2 flags it when due). Mirrors
        // the Telegram digest's needs-attention title rule.
        Notification::assertNothingSent();
    }

    public function test_buyback_queue_triggers_email_and_snapshots_late_count(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $loan = $this->makeLateLoan();
        $loan->forceFill(['buyback_eligible_at' => now()->subDay()])->save();

        Artisan::call('telegram:digest');

        Notification::assertSentTo(
            $admin,
            AdminActionItemsNotification::class,
            fn (AdminActionItemsNotification $n) => $n->buybackQueue === 1
                && $n->loansLate === 1
                && $n->kycPending === 0
                && $n->depositsPending === 0
                && $n->withdrawalsPending === 0,
        );
    }

    public function test_dismissed_and_executed_buybacks_do_not_trigger_email(): void
    {
        Notification::fake();
        $this->makeAdmin();
        // Admin-dismissed queue entry — out of the queue until reactivated.
        $this->makeLateLoan()->forceFill([
            'buyback_eligible_at' => now()->subDays(5),
            'buyback_dismissed_at' => now()->subDays(2),
        ])->save();
        // Already-executed buyback — terminal, no longer actionable.
        $this->makeLateLoan()->forceFill([
            'buyback_eligible_at' => now()->subDays(5),
            'bought_back_at' => now()->subDay(),
        ])->save();

        Artisan::call('telegram:digest');

        Notification::assertNothingSent();
    }

    public function test_email_sent_even_when_telegram_not_configured(): void
    {
        Notification::fake();
        config(['services.telegram.bot_token' => null]);
        $admin = $this->makeAdmin();
        User::factory()->create(['kyc_status' => 'submitted']);

        Artisan::call('telegram:digest');

        Notification::assertSentTo($admin, AdminActionItemsNotification::class);
        Http::assertNothingSent();
    }

    public function test_telegram_digest_still_sent_alongside_email(): void
    {
        Notification::fake();
        $this->makeAdmin();
        User::factory()->create(['kyc_status' => 'submitted']);

        Artisan::call('telegram:digest');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', 'има задачи за обработка'));
    }

    /**
     * Best-effort contract: an email-dispatch failure is reported but the
     * command still exits SUCCESS, and the Telegram digest (sent first)
     * has already gone out. Pins both the try/catch and the
     * Telegram-before-email ordering.
     */
    public function test_email_dispatch_failure_does_not_fail_command_or_block_telegram(): void
    {
        $this->makeAdmin();
        User::factory()->create(['kyc_status' => 'submitted']);

        $this->app->instance(
            Dispatcher::class,
            Mockery::mock(Dispatcher::class, function ($mock) {
                $mock->shouldReceive('send')->andThrow(new RuntimeException('queue down'));
            }),
        );

        $exitCode = Artisan::call('telegram:digest');

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', 'Сутрешно резюме'));
    }

    /**
     * Contract guard: the stored database payload keeps `run_at` as an
     * ISO-8601 string (via()'s dedupe query matches it exactly) and
     * `format` === 'filament' (Filament's bell inbox filters on it —
     * without the key the row is invisible in the admin panel).
     */
    public function test_database_payload_contract(): void
    {
        $runAt = now();
        $notification = new AdminActionItemsNotification(
            kycPending: 1, depositsPending: 2, withdrawalsPending: 3,
            buybackQueue: 4, loansLate: 5, runAt: $runAt,
        );

        $payload = $notification->toDatabase($this->makeAdmin());

        $this->assertSame('filament', $payload['format']);
        $this->assertSame('Задачи за обработка: 1 KYC · 2 депозита · 3 тегления · 4 buyback', $payload['title']);
        $this->assertSame($runAt->toIso8601String(), $payload['run_at']);
        $this->assertSame('admin_action_items_digest', $payload['type']);
        $this->assertSame(1, $payload['kyc_pending']);
        $this->assertSame(2, $payload['deposits_pending']);
        $this->assertSame(3, $payload['withdrawals_pending']);
        $this->assertSame(4, $payload['buyback_queue']);
        $this->assertSame(5, $payload['loans_late']);
    }

    /**
     * Dispatch-time dedupe, both directions: a row from a PREVIOUS run
     * (different run_at) must not suppress today's dispatch; a row with
     * the SAME run_at must. (This guards repeated dispatches only — via()
     * is not re-consulted on queue-worker retries; see the notification
     * class docblock.)
     */
    public function test_dedupe_suppresses_same_run_at_but_not_previous_runs(): void
    {
        $admin = $this->makeAdmin();
        $runAt = now();
        $notification = new AdminActionItemsNotification(
            kycPending: 1, depositsPending: 0, withdrawalsPending: 0,
            buybackQueue: 0, loansLate: 0, runAt: $runAt,
        );

        $this->assertSame(['mail', 'database'], $notification->via($admin));

        // Yesterday's digest row — must NOT suppress today's.
        $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminActionItemsNotification::class,
            'data' => array_merge(
                $notification->toDatabase($admin),
                ['run_at' => now()->subDay()->toIso8601String()],
            ),
            'read_at' => null,
        ]);

        $this->assertSame(['mail', 'database'], $notification->via($admin),
            'A row from a previous cron run must not suppress today\'s delivery');

        // Same run_at already stored — repeated dispatch is suppressed.
        $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => AdminActionItemsNotification::class,
            'data' => $notification->toDatabase($admin),
            'read_at' => null,
        ]);

        $this->assertSame([], $notification->via($admin),
            'Existing database row with the same run_at must suppress a repeated dispatch');
    }

    /**
     * The email renders only the non-zero rows: an admin reading "има
     * задачи" must not wade through zero-count noise. Rendering also
     * pins the Blade template against syntax regressions.
     */
    public function test_mail_renders_only_nonzero_rows(): void
    {
        // Sentinel app name — makes the "hardcoded, not APP_NAME" guard
        // deterministic in every environment (incl. APP_NAME=Vamaasset).
        config(['app.name' => 'NotVamaasset']);

        $admin = $this->makeAdmin();
        $notification = new AdminActionItemsNotification(
            kycPending: 1, depositsPending: 0, withdrawalsPending: 0,
            buybackQueue: 0, loansLate: 2, runAt: now(),
        );

        $mail = $notification->toMail($admin);
        $html = $mail->render();

        $this->assertStringContainsString('1 KYC', $mail->subject);
        $this->assertStringContainsString('KYC чакащи преглед', $html);
        $this->assertStringNotContainsString('Тегления чакащи обработка', $html);
        $this->assertStringNotContainsString('Депозити чакащи потвърждение', $html);
        $this->assertStringNotContainsString('Buyback queue', $html);
        $this->assertStringContainsString('late/default', $html);
        // Brand is hardcoded — the signature must not follow APP_NAME.
        $this->assertStringContainsString('екипът на Vamaasset', $html);
        $this->assertStringNotContainsString('екипът на NotVamaasset', $html,
            'Signature must be hardcoded, not follow APP_NAME');
    }
}
