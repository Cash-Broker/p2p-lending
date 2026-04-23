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
        // Backdate measured_at on the run timestamp row (used by health threshold).
        PlatformMetric::where('key', 'last_late_check_run_at')->update(['measured_at' => $when]);
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
}
