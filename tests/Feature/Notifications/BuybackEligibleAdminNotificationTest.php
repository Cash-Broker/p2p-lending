<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\BuybackEligibleAdminNotification;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract guards for the F2 admin digest notification (the "Step 7"
 * tests its docblock referenced but which did not exist until the
 * 2026-08-07 notification-pattern cleanup).
 *
 * If these fail, do NOT update the test to match new behaviour without
 * first reading wasRecentlyNotified() / toDatabase() and confirming the
 * dedupe query and the Filament inbox filter still hold.
 */
class BuybackEligibleAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeNotification(?CarbonInterface $runAt = null): BuybackEligibleAdminNotification
    {
        return new BuybackEligibleAdminNotification(
            newlyEligibleCount: 2,
            waitingMoreThan3DaysCount: 1,
            loanIds: [11, 12],
            runAt: $runAt ?? now(),
        );
    }

    /**
     * Contract: the stored database payload keeps `run_at` as an
     * ISO-8601 string (via()'s dedupe query matches it exactly) and
     * `format` === 'filament' (Filament's bell inbox filters on it —
     * without the key the row is invisible in the admin panel).
     */
    public function test_database_payload_contract(): void
    {
        $runAt = now();
        $notification = $this->makeNotification($runAt);

        $payload = $notification->toDatabase($this->makeAdmin());

        $this->assertSame('filament', $payload['format']);
        $this->assertSame('Buyback Queue: 2 нови · 1 чака > 3 дни', $payload['title']);
        $this->assertSame($runAt->toIso8601String(), $payload['run_at']);
        $this->assertSame('buyback_eligible_admin_digest', $payload['type']);
        $this->assertSame(2, $payload['newly_eligible_count']);
        $this->assertSame(1, $payload['waiting_more_than_3_days_count']);
        $this->assertSame([11, 12], $payload['loan_ids']);

        // Both verb branches of the BG pluralisation.
        $plural = new BuybackEligibleAdminNotification(
            newlyEligibleCount: 1,
            waitingMoreThan3DaysCount: 2,
            loanIds: [11],
            runAt: $runAt,
        );
        $this->assertSame(
            'Buyback Queue: 1 нов · 2 чакат > 3 дни',
            $plural->toDatabase($this->makeAdmin())['title'],
        );
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
        $notification = $this->makeNotification();

        $this->assertSame(['mail', 'database'], $notification->via($admin));

        // Yesterday's digest row — must NOT suppress today's.
        $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => BuybackEligibleAdminNotification::class,
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
            'type' => BuybackEligibleAdminNotification::class,
            'data' => $notification->toDatabase($admin),
            'read_at' => null,
        ]);

        $this->assertSame([], $notification->via($admin),
            'Existing database row with the same run_at must suppress a repeated dispatch');
    }

    /**
     * Rendering pins the Blade template and the hardcoded brand — the
     * signature must not follow APP_NAME (subject/header/footer are all
     * hardcoded 'Vamaasset').
     */
    public function test_mail_renders_with_vamaasset_signature(): void
    {
        // Sentinel app name — makes the "hardcoded, not APP_NAME" guard
        // deterministic in every environment (incl. APP_NAME=Vamaasset).
        config(['app.name' => 'NotVamaasset']);

        $admin = $this->makeAdmin();
        $mail = $this->makeNotification()->toMail($admin);

        $html = $mail->render();

        $this->assertStringContainsString('2 нови кредита', $html);
        $this->assertStringContainsString('#11, #12', $html);
        $this->assertStringContainsString('екипът на Vamaasset', $html);
        $this->assertStringNotContainsString('екипът на NotVamaasset', $html,
            'Signature must be hardcoded, not follow APP_NAME');
    }
}
