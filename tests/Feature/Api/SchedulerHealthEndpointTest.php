<?php

namespace Tests\Feature\Api;

use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase F1 Step 3 — public scheduler health endpoint for external
 * monitoring (UptimeRobot etc.). 4 status states + rate-limit + no-auth.
 */
class SchedulerHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function recordRunAt(Carbon $when): void
    {
        // --- Late-check metrics (F1) ---
        // PlatformMetric::record stamps measured_at = now(), so for the
        // "stale" tests we mutate the row directly afterward.
        PlatformMetric::record('last_late_check_run_at', $when->toIso8601String());
        PlatformMetric::record('last_late_check_status', 'success');
        PlatformMetric::record('last_late_check_loans_scanned', '5');
        PlatformMetric::record('last_late_check_schedules_marked', '1');
        PlatformMetric::record('last_late_check_loans_to_late', '1');
        PlatformMetric::record('last_late_check_loans_recovered', '0');
        PlatformMetric::record('last_late_check_recovery_skipped_default', '0');
        PlatformMetric::record('last_late_check_notifications_queued', '3');
        PlatformMetric::where('key', 'last_late_check_run_at')->update(['measured_at' => $when]);

        // --- Buyback-check metrics (F2) ---
        // Mirror the late-check timestamp so both schedulers share the same
        // health tier in these F1 tests (the top-level status is the worst
        // of the two). F2-specific tests in Step 7 exercise buyback-only
        // states by writing these metrics independently.
        PlatformMetric::record('last_buyback_check_run_at', $when->toIso8601String());
        PlatformMetric::record('last_buyback_check_status', 'success');
        PlatformMetric::record('last_buyback_check_loans_newly_eligible', '0');
        PlatformMetric::record('last_buyback_check_notifications_queued', '0');
        PlatformMetric::record('last_buyback_check_enabled', 'true');
        PlatformMetric::where('key', 'last_buyback_check_run_at')->update(['measured_at' => $when]);

        // --- Payouts metrics (always in the worst-of; no kill switch) ---
        $this->recordPayoutsOnly($when);
    }

    /** Helper — record only the payouts metrics at a specific time. */
    private function recordPayoutsOnly(Carbon $when): void
    {
        PlatformMetric::record('last_payouts_run_at', $when->toIso8601String());
        PlatformMetric::record('last_payouts_status', 'success');
        PlatformMetric::record('last_payouts_loans_processed', '0');
        PlatformMetric::record('last_payouts_loans_failed', '0');
        PlatformMetric::where('key', 'last_payouts_run_at')->update(['measured_at' => $when]);

        // Reconcile is always in the worst-of (audit 2026-09-01) — a healthy
        // fixture has to carry it, exactly like payouts.
        $this->recordReconcile($when);
    }

    private function recordReconcile(Carbon $when, string $status = 'ok'): void
    {
        PlatformMetric::record('last_reconcile_run_at', $when->toIso8601String());
        PlatformMetric::record('last_reconcile_status', $status);
        PlatformMetric::record('last_reconcile_wallets_checked', '3');
        PlatformMetric::record('last_reconcile_mismatches', $status === 'ok' ? '0' : '1');
        PlatformMetric::where('key', 'last_reconcile_run_at')->update(['measured_at' => $when]);
    }

    public function test_healthy_when_run_within_last_26_hours(): void
    {
        $this->recordRunAt(now()->subHours(2));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('expected_interval_minutes', 1440)
            ->assertJsonPath('late_check_enabled', true)
            ->assertJsonPath('last_run_stats.loans_scanned', 5)
            ->assertJsonPath('last_run_stats.notifications_queued', 3)
            ->assertJsonPath('last_run_stats.recovery_skipped_default', 0);
    }

    public function test_warning_when_between_26_and_48_hours_stale(): void
    {
        $this->recordRunAt(now()->subHours(30));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'warning');
    }

    public function test_critical_503_when_more_than_48_hours_stale(): void
    {
        $this->recordRunAt(now()->subHours(60));

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical');
    }

    public function test_critical_503_when_never_run(): void
    {
        // No metric records at all.
        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('last_run_at', null);
    }

    public function test_endpoint_requires_no_authentication(): void
    {
        $this->recordRunAt(now()->subHours(1));

        // No actingAs() call — must work for an anonymous monitor.
        $this->getJson('/api/health/scheduler')->assertOk();
    }

    public function test_late_check_enabled_setting_reflected_in_response(): void
    {
        $this->recordRunAt(now()->subHours(1));
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('late_check_enabled', false);
    }

    // ═════════════════════════════════════════════════════════════════
    // F2 EXTENSIONS — dual-scheduler worst-of-two semantics + disabled-
    // ignored rule. Each test exercises ONE axis of the new behaviour.
    // ═════════════════════════════════════════════════════════════════

    /** Helper — record only the late-check metrics at a specific time. */
    private function recordLateOnly(Carbon $when): void
    {
        PlatformMetric::record('last_late_check_run_at', $when->toIso8601String());
        PlatformMetric::record('last_late_check_status', 'success');
        PlatformMetric::where('key', 'last_late_check_run_at')->update(['measured_at' => $when]);
    }

    /** Helper — record only the buyback-check metrics at a specific time. */
    private function recordBuybackOnly(Carbon $when): void
    {
        PlatformMetric::record('last_buyback_check_run_at', $when->toIso8601String());
        PlatformMetric::record('last_buyback_check_status', 'success');
        PlatformMetric::record('last_buyback_check_loans_newly_eligible', '0');
        PlatformMetric::record('last_buyback_check_notifications_queued', '0');
        PlatformMetric::where('key', 'last_buyback_check_run_at')->update(['measured_at' => $when]);
    }

    public function test_late_healthy_and_buyback_critical_returns_overall_critical_503(): void
    {
        // Late ran 1 h ago (healthy). Buyback never ran (critical).
        // Both schedulers enabled. Overall = critical; HTTP 503.
        $when = now()->subHours(1);
        $this->recordLateOnly($when);
        // NO buyback metric at all → buyback status = critical.

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('last_run_at', $when->toIso8601String())  // late field still populated
            ->assertJsonPath('buyback.last_run_at', null);
    }

    public function test_late_critical_and_buyback_healthy_returns_overall_critical_503(): void
    {
        $this->recordBuybackOnly(now()->subHours(1));
        // NO late metric → late status = critical.

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('last_run_at', null)
            ->assertJsonPath('buyback.status', 'healthy');
    }

    public function test_both_healthy_returns_overall_healthy_200(): void
    {
        $this->recordRunAt(now()->subHours(1));  // seeds both late AND buyback

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('buyback.status', 'healthy');
    }

    public function test_late_disabled_and_buyback_healthy_returns_overall_healthy_200(): void
    {
        // Late disabled via platform_settings. Buyback + payouts ran 1 h ago.
        // Per ops rule, disabled schedulers are EXCLUDED from the worst-of
        // — overall = healthy.
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);
        $this->recordBuybackOnly(now()->subHours(1));
        $this->recordPayoutsOnly(now()->subHours(1));
        // Intentionally NO late metric — scheduler off.

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('late_check_enabled', false)
            ->assertJsonPath('buyback.enabled', true);
    }

    public function test_both_toggleable_disabled_payouts_alone_decides_overall(): void
    {
        // Late + buyback toggled off deliberately — excluded from worst-of.
        // Payouts has NO kill switch, so it alone carries the overall status:
        // healthy when fresh…
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);
        PlatformSetting::where('key', 'buyback_check_enabled')->update(['value' => 'false']);
        $this->recordPayoutsOnly(now()->subHours(1));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('late_check_enabled', false)
            ->assertJsonPath('buyback.enabled', false);
    }

    public function test_buyback_block_structure_present(): void
    {
        $this->recordRunAt(now()->subHours(1));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'buyback' => [
                    'status',
                    'last_run_at',
                    'minutes_since_last_run',
                    'expected_interval_minutes',
                    'enabled',
                    'last_run_stats' => [
                        'status',
                        'loans_newly_eligible',
                        'notifications_queued',
                    ],
                ],
            ]);
    }

    // ═════════════════════════════════════════════════════════════════
    // PAYOUTS EXTENSION (2026-08-17) — the cron that PAYS investors is now
    // part of the worst-of, unconditionally (no kill switch exists for it).
    // ═════════════════════════════════════════════════════════════════

    public function test_payouts_never_run_is_critical_even_when_other_schedulers_are_healthy(): void
    {
        $this->recordLateOnly(now()->subHours(1));
        $this->recordBuybackOnly(now()->subHours(1));
        // NO payouts metric → the money cron is invisible → critical.

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('payouts.status', 'critical')
            ->assertJsonPath('payouts.last_run_at', null);
    }

    public function test_payouts_stale_between_26_and_48_hours_degrades_overall_to_warning(): void
    {
        $this->recordLateOnly(now()->subHours(1));
        $this->recordBuybackOnly(now()->subHours(1));
        $this->recordPayoutsOnly(now()->subHours(30));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('payouts.status', 'warning');
    }

    public function test_payouts_block_structure_present_without_enabled_toggle(): void
    {
        $this->recordRunAt(now()->subHours(1));

        $response = $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonStructure([
                'payouts' => [
                    'status',
                    'last_run_at',
                    'minutes_since_last_run',
                    'expected_interval_minutes',
                    'last_run_stats' => ['status', 'loans_processed', 'loans_failed'],
                ],
            ]);

        // Deliberately NO `enabled` field — there is no payouts kill switch.
        $this->assertArrayNotHasKey('enabled', $response->json('payouts'));
    }

    public function test_process_payouts_command_writes_the_health_metrics(): void
    {
        // End-to-end: run the real command (no automatic loans in this test
        // DB — it processes nothing but MUST still stamp the metrics), then
        // the endpoint reads them back as a healthy payouts scheduler.
        $this->recordLateOnly(now()->subHours(1));
        $this->recordBuybackOnly(now()->subHours(1));
        $this->recordReconcile(now()->subHours(1));

        $this->artisan('loans:process-payouts')->assertSuccessful();

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('payouts.status', 'healthy')
            ->assertJsonPath('payouts.last_run_stats.status', 'success')
            ->assertJsonPath('payouts.last_run_stats.loans_processed', 0)
            ->assertJsonPath('payouts.last_run_stats.loans_failed', 0);
    }

    public function test_backwards_compat_legacy_flat_fields_still_populated(): void
    {
        // F1-era monitors parse the flat top-level fields — the F2
        // extension must NOT break these. Pin their presence explicitly.
        $this->recordRunAt(now()->subHours(1));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'last_run_at',
                'minutes_since_last_run',
                'expected_interval_minutes',
                'late_check_enabled',
                'last_run_stats' => [
                    'status',
                    'loans_scanned',
                    'schedules_marked_late',
                    'loans_transitioned_to_late',
                    'loans_recovered',
                    'recovery_skipped_default',
                    'notifications_queued',
                ],
            ])
            ->assertJsonPath('last_run_stats.loans_scanned', 5);  // value from recordRunAt helper
    }

    // ═════════ Audit 2026-09-01 (A3): reconcile + queue + failed payout run ═════════

    public function test_reconcile_never_run_is_critical_even_when_everything_else_is_healthy(): void
    {
        $this->recordRunAt(now()->subHours(1));
        PlatformMetric::where('key', 'like', 'last_reconcile_%')->delete();

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('reconcile.status', 'critical')
            ->assertJsonPath('reconcile.last_run_at', null);
    }

    public function test_reconcile_mismatch_is_critical_regardless_of_freshness(): void
    {
        $this->recordRunAt(now()->subHours(1));
        $this->recordReconcile(now()->subMinutes(5), 'mismatch');

        $this->getJson('/api/health/scheduler')
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonPath('reconcile.status', 'critical')
            ->assertJsonPath('reconcile.last_run_stats.status', 'mismatch')
            ->assertJsonPath('reconcile.last_run_stats.mismatches', 1);
    }

    public function test_payout_run_with_failed_loans_degrades_payouts_to_warning(): void
    {
        $this->recordRunAt(now()->subHours(1));
        PlatformMetric::record('last_payouts_status', 'failure');
        PlatformMetric::record('last_payouts_loans_failed', '2');

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('payouts.status', 'warning')
            ->assertJsonPath('payouts.last_run_stats.loans_failed', 2);
    }

    public function test_queue_block_is_healthy_when_nothing_waits(): void
    {
        $this->recordRunAt(now()->subHours(1));

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('queue.status', 'healthy')
            ->assertJsonPath('queue.pending_jobs', 0)
            ->assertJsonPath('queue.failed_jobs_24h', 0);
    }

    public function test_job_waiting_longer_than_fifteen_minutes_degrades_queue_to_warning_not_critical(): void
    {
        $this->recordRunAt(now()->subHours(1));
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(30)->getTimestamp(),
            'created_at' => now()->subMinutes(30)->getTimestamp(),
        ]);

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('queue.status', 'warning')
            ->assertJsonPath('queue.pending_jobs', 1);
    }
}
