<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

/**
 * Public health endpoint for external monitoring (UptimeRobot, Pingdom,
 * Healthchecks.io, etc.) to verify the loans:process-late scheduler is
 * actually running on its 24-hour cadence.
 *
 * No auth — the endpoint exposes only operational metrics, never PII or
 * financial data. Rate-limited 60/min to deflect abuse.
 *
 * Status semantics:
 *   healthy   — last run was within the expected interval (≤ 26 h ago).
 *   warning   — last run was between 26 h and 48 h ago. Scheduler is late
 *               but probably just one missed window — operator should
 *               investigate today.
 *   critical  — never run, OR last run was > 48 h ago. Two missed windows
 *               at minimum — automation is effectively down. Page the
 *               on-call.
 *
 * The 26 h figure is 24 h + 2 h grace (e.g. cron drift, command runtime).
 */
class SchedulerHealthController extends Controller
{
    private const EXPECTED_INTERVAL_MIN = 24 * 60; // 1440
    private const WARNING_THRESHOLD_MIN = 26 * 60; // 1560
    private const CRITICAL_THRESHOLD_MIN = 48 * 60; // 2880

    public function __invoke(): JsonResponse
    {
        $lastRunAt = PlatformMetric::measuredAt('last_late_check_run_at');
        $minutesSince = $lastRunAt ? (int) $lastRunAt->diffInMinutes(now()) : null;

        $status = match (true) {
            $minutesSince === null                              => 'critical',
            $minutesSince > self::CRITICAL_THRESHOLD_MIN        => 'critical',
            $minutesSince > self::WARNING_THRESHOLD_MIN         => 'warning',
            default                                             => 'healthy',
        };

        return response()->json([
            'status' => $status,
            'last_run_at' => $lastRunAt?->toIso8601String(),
            'minutes_since_last_run' => $minutesSince,
            'expected_interval_minutes' => self::EXPECTED_INTERVAL_MIN,
            'late_check_enabled' => (bool) PlatformSetting::get('late_check_enabled', true),
            'last_run_stats' => [
                'status' => PlatformMetric::read('last_late_check_status'),
                'loans_scanned' => $this->intMetric('last_late_check_loans_scanned'),
                'schedules_marked_late' => $this->intMetric('last_late_check_schedules_marked'),
                'loans_transitioned_to_late' => $this->intMetric('last_late_check_loans_to_late'),
                'loans_recovered' => $this->intMetric('last_late_check_loans_recovered'),
                // Loans that the auto-recovery safeguard refused because they
                // had schedule items in 'default' status — operator must
                // resolve manually. Visible in the dashboard so it doesn't
                // hide in laravel.log.
                'recovery_skipped_default' => $this->intMetric('last_late_check_recovery_skipped_default'),
                'notifications_queued' => $this->intMetric('last_late_check_notifications_queued'),
            ],
        ], $status === 'critical' ? 503 : 200);
    }

    private function intMetric(string $key): ?int
    {
        $value = PlatformMetric::read($key);
        return $value !== null ? (int) $value : null;
    }
}
