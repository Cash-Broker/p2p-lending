<?php

namespace Tests\Feature\Api;

use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F1 Step 3 — public scheduler health endpoint for external
 * monitoring (UptimeRobot etc.). 4 status states + rate-limit + no-auth.
 */
class SchedulerHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function recordRunAt(\Carbon\Carbon $when): void
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
    private function recordLateOnly(\Carbon\Carbon $when): void
    {
        PlatformMetric::record('last_late_check_run_at', $when->toIso8601String());
        PlatformMetric::record('last_late_check_status', 'success');
        PlatformMetric::where('key', 'last_late_check_run_at')->update(['measured_at' => $when]);
    }

    /** Helper — record only the buyback-check metrics at a specific time. */
    private function recordBuybackOnly(\Carbon\Carbon $when): void
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
        // Late disabled via platform_settings. Buyback ran 1 h ago.
        // Per ops rule, disabled schedulers are EXCLUDED from worst-of-two
        // — overall = buyback status = healthy.
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);
        $this->recordBuybackOnly(now()->subHours(1));
        // Intentionally NO late metric — scheduler off.

        $this->getJson('/api/health/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('late_check_enabled', false)
            ->assertJsonPath('buyback.enabled', true);
    }

    public function test_both_disabled_returns_overall_healthy_ops_decision(): void
    {
        // Both schedulers toggled off deliberately. No metric rows at all.
        // Ops rule: no active schedulers = nothing to fail = healthy.
        PlatformSetting::where('key', 'late_check_enabled')->update(['value' => 'false']);
        PlatformSetting::where('key', 'buyback_check_enabled')->update(['value' => 'false']);

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
}
