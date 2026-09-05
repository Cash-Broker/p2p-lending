<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Services\Loans\PayoutPauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public health endpoint for external monitoring (UptimeRobot, Pingdom,
 * Healthchecks.io, etc.) to verify that the daily schedulers are running
 * on their 24-hour cadence:
 *   1. loans:process-late                  (F1, 03:30 daily)
 *   2. loans:detect-buyback-eligible       (F2, 03:45 daily)
 *   3. loans:process-payouts               (04:00 daily — added 2026-08-17;
 *      this is the cron that PAYS investors, its silent death was the one
 *      failure nobody would see until an investor complained)
 *
 * No auth — the endpoint exposes only operational metrics, never PII or
 * financial data. Rate-limited 60/min to deflect abuse.
 *
 * Status semantics per scheduler:
 *   healthy   — last run ≤ 26 h ago.
 *   warning   — last run 26–48 h ago. One missed window — operator should
 *               investigate today.
 *   critical  — never run, OR last run > 48 h ago. Two missed windows —
 *               automation is effectively down.
 *
 * The top-level `status` is the WORST of the schedulers' statuses, but
 * **DISABLED schedulers are excluded** from the worst-of computation
 * (per ops rule): if an operator deliberately toggled a scheduler off via
 * its `*_check_enabled` platform setting, that absence is intentional and
 * should not raise an alert. The payouts scheduler HAS NO kill switch —
 * it is always part of the worst-of (paying investors is never optional).
 * ⚠ Deploy note: run `php artisan loans:process-payouts` once after the
 * release that ships this block — otherwise the missing metric reads as
 * critical (never run) until the first 04:00 cron.
 *
 * HTTP 503 iff top-level == 'critical'. This means an external monitor
 * configured against this endpoint pages on ANY scheduler going down
 * — a single-URL pane for all three cron jobs.
 *
 * Backwards compatibility (F1 shape preserved):
 *   The F1 flat fields at the top level (`last_run_at`, `minutes_since_last_run`,
 *   `expected_interval_minutes`, `late_check_enabled`, `last_run_stats`)
 *   still describe the late-check scheduler specifically. F1-era
 *   monitors parsing these fields continue to work unchanged.
 *   F2 adds a nested `buyback` block, and the payouts extension a nested
 *   `payouts` block, each with the equivalent shape.
 */
class SchedulerHealthController extends Controller
{
    private const EXPECTED_INTERVAL_MIN = 24 * 60;   // 1440

    private const WARNING_THRESHOLD_MIN = 26 * 60;   // 1560

    private const CRITICAL_THRESHOLD_MIN = 48 * 60;  // 2880

    /** A job waiting this long means the worker is not running. */
    private const QUEUE_STALE_SECONDS = 15 * 60;

    public function __invoke(): JsonResponse
    {
        // --- F1: late check ---
        $lateLastRunAt = PlatformMetric::measuredAt('last_late_check_run_at');
        $lateMinutesSince = $lateLastRunAt ? (int) $lateLastRunAt->diffInMinutes(now()) : null;
        $lateStatus = $this->statusFromMinutes($lateMinutesSince);
        $lateCheckEnabled = (bool) PlatformSetting::get('late_check_enabled', true);

        // --- F2: buyback check ---
        $buybackLastRunAt = PlatformMetric::measuredAt('last_buyback_check_run_at');
        $buybackMinutesSince = $buybackLastRunAt ? (int) $buybackLastRunAt->diffInMinutes(now()) : null;
        $buybackStatus = $this->statusFromMinutes($buybackMinutesSince);
        $buybackCheckEnabled = (bool) PlatformSetting::get('buyback_check_enabled', true);

        // --- Payouts (04:00 cron that pays investors) ---
        $payoutsLastRunAt = PlatformMetric::measuredAt('last_payouts_run_at');
        $payoutsMinutesSince = $payoutsLastRunAt ? (int) $payoutsLastRunAt->diffInMinutes(now()) : null;
        $payoutsStatus = $this->statusFromMinutes($payoutsMinutesSince);

        // Audit 2026-09-01 (PAY-16): the run happened on time but at least one
        // loan failed inside it — its investors were not paid that morning.
        // Warning here; the loud alert is the admin mail + Telegram from the command.
        if ($payoutsStatus === 'healthy' && PlatformMetric::read('last_payouts_status') === 'failure') {
            $payoutsStatus = 'warning';
        }

        // Audit 2026-09-01 (A3): the 03:00 ledger reconciliation used to leave no
        // trace anywhere but its e-mail. Stale = critical like every money cron;
        // a recorded mismatch stays critical regardless of age until the next clean run.
        $reconcileLastRunAt = PlatformMetric::measuredAt('last_reconcile_run_at');
        $reconcileMinutesSince = $reconcileLastRunAt ? (int) $reconcileLastRunAt->diffInMinutes(now()) : null;
        $reconcileLastStatus = PlatformMetric::read('last_reconcile_status');
        $reconcileStatus = $this->statusFromMinutes($reconcileMinutesSince);
        if ($reconcileLastStatus !== null && $reconcileLastStatus !== 'ok') {
            $reconcileStatus = 'critical';
        }

        $queue = $this->queueSnapshot();

        // Top-level = WORST of the schedulers, but DISABLED schedulers are
        // excluded from the computation (ops rule: a deliberately-toggled-off
        // scheduler mustn't trigger a critical alert). Payouts has no kill
        // switch — paying investors is never optional, so it always counts.
        $statuses = [$payoutsStatus, $reconcileStatus, $queue['status']];
        if ($lateCheckEnabled) {
            $statuses[] = $lateStatus;
        }
        if ($buybackCheckEnabled) {
            $statuses[] = $buybackStatus;
        }
        $overall = array_reduce($statuses, fn ($carry, $s) => $this->worstStatus($carry, $s), 'healthy');

        return response()->json([
            'status' => $overall,

            // --- F1 flat fields (legacy; describe the late-check scheduler) ---
            'last_run_at' => $lateLastRunAt?->toIso8601String(),
            'minutes_since_last_run' => $lateMinutesSince,
            'expected_interval_minutes' => self::EXPECTED_INTERVAL_MIN,
            'late_check_enabled' => $lateCheckEnabled,
            'last_run_stats' => [
                'status' => PlatformMetric::read('last_late_check_status'),
                'loans_scanned' => $this->intMetric('last_late_check_loans_scanned'),
                'schedules_marked_late' => $this->intMetric('last_late_check_schedules_marked'),
                'loans_transitioned_to_late' => $this->intMetric('last_late_check_loans_to_late'),
                'loans_recovered' => $this->intMetric('last_late_check_loans_recovered'),
                'recovery_skipped_default' => $this->intMetric('last_late_check_recovery_skipped_default'),
                'notifications_queued' => $this->intMetric('last_late_check_notifications_queued'),
            ],

            // --- F2 buyback block ---
            'buyback' => [
                'status' => $buybackStatus,
                'last_run_at' => $buybackLastRunAt?->toIso8601String(),
                'minutes_since_last_run' => $buybackMinutesSince,
                'expected_interval_minutes' => self::EXPECTED_INTERVAL_MIN,
                'enabled' => $buybackCheckEnabled,
                'last_run_stats' => [
                    'status' => PlatformMetric::read('last_buyback_check_status'),
                    'loans_newly_eligible' => $this->intMetric('last_buyback_check_loans_newly_eligible'),
                    'notifications_queued' => $this->intMetric('last_buyback_check_notifications_queued'),
                ],
            ],

            // --- Payouts block (no `enabled` field — there is no kill switch) ---
            'payouts' => [
                'status' => $payoutsStatus,
                'last_run_at' => $payoutsLastRunAt?->toIso8601String(),
                'minutes_since_last_run' => $payoutsMinutesSince,
                'expected_interval_minutes' => self::EXPECTED_INTERVAL_MIN,
                // PAY-13: a pause is not a health degradation — informational only.
                'payout_pause_enabled' => PayoutPauseService::isEnabled(),
                'last_run_stats' => [
                    'status' => PlatformMetric::read('last_payouts_status'),
                    'loans_processed' => $this->intMetric('last_payouts_loans_processed'),
                    'loans_failed' => $this->intMetric('last_payouts_loans_failed'),
                    'loans_paused' => $this->intMetric('last_payouts_loans_paused'),
                    'pause_newly_paused' => $this->intMetric('last_payouts_pause_newly_paused'),
                    'pause_resumed' => $this->intMetric('last_payouts_pause_resumed'),
                ],
            ],

            'reconcile' => [
                'status' => $reconcileStatus,
                'last_run_at' => $reconcileLastRunAt?->toIso8601String(),
                'minutes_since_last_run' => $reconcileMinutesSince,
                'expected_interval_minutes' => self::EXPECTED_INTERVAL_MIN,
                'last_run_stats' => [
                    'status' => $reconcileLastStatus,
                    'wallets_checked' => $this->intMetric('last_reconcile_wallets_checked'),
                    'mismatches' => $this->intMetric('last_reconcile_mismatches'),
                ],
            ],

            'queue' => $queue,

            // SEC-22: informational only — a missed run delays a closure by a day
            // and emailOutputOnFailure covers failures; deliberately NOT in the worst-of.
            'account_deletions' => [
                'last_run_at' => PlatformMetric::measuredAt('last_account_deletions_run_at')?->toIso8601String(),
                'last_run_stats' => ['status' => PlatformMetric::read('last_account_deletions_status')],
            ],
        ], $overall === 'critical' ? 503 : 200);
    }

    /**
     * Live view of the database queue. Every admin alert and investor mail is a
     * queued job — a stopped Supervisor worker is otherwise invisible until
     * somebody notices the silence. Capped at «warning»: a stuck queue delays
     * notifications, it does not move money, so it must not flip the 503 the
     * uptime monitor reserves for the money crons.
     */
    private function queueSnapshot(): array
    {
        $pending = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $oldestAvailableAt = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $oldestWaitingSeconds = $oldestAvailableAt !== null ? max(0, now()->getTimestamp() - (int) $oldestAvailableAt) : 0;
        $failedLast24h = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $lastBusyAt = PlatformMetric::measuredAt('last_queue_busy_at');

        return [
            'status' => ($oldestWaitingSeconds > self::QUEUE_STALE_SECONDS || $failedLast24h > 0) ? 'warning' : 'healthy',
            'pending_jobs' => $pending,
            'oldest_waiting_seconds' => $oldestWaitingSeconds,
            'failed_jobs_24h' => $failedLast24h,
            'last_busy_at' => $lastBusyAt?->toIso8601String(),
        ];
    }

    private function statusFromMinutes(?int $minutes): string
    {
        return match (true) {
            $minutes === null => 'critical',
            $minutes > self::CRITICAL_THRESHOLD_MIN => 'critical',
            $minutes > self::WARNING_THRESHOLD_MIN => 'warning',
            default => 'healthy',
        };
    }

    /**
     * Return the worst (most severe) of two statuses.
     * critical > warning > healthy.
     */
    private function worstStatus(string $a, string $b): string
    {
        $rank = ['healthy' => 0, 'warning' => 1, 'critical' => 2];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    private function intMetric(string $key): ?int
    {
        $value = PlatformMetric::read($key);

        return $value !== null ? (int) $value : null;
    }
}
