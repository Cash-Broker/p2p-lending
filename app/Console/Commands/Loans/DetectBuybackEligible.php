<?php

namespace App\Console\Commands\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\BuybackEligibleAdminNotification;
use App\Services\Loans\BuybackCalculationService;
use App\Services\Loans\BuybackEligibilityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily buyback-eligibility detection cron. Mirrors F1's ProcessLateLoans
 * shape: same flag set, same concurrency lock, same dry-run wrapper, same
 * metric-writing conventions.
 *
 * Scheduled at 03:45 (after `loans:process-late` at 03:30) by
 * bootstrap/app.php. Detection READS the late statuses set by F1's cron —
 * the 15-min buffer should comfortably cover F1's run time in production.
 * If F1 ever exceeds 15 min, bump F2 to 04:00 (documented in CLAUDE.md).
 *
 * -------------------------------------------------------------------------
 * IDEMPOTENCY
 *
 * BuybackEligibilityService's query filters
 *   loan.buyback_eligible_at IS NULL
 * so a loan already flagged in a PRIOR run is not re-surfaced here.
 * The original `buyback_eligible_at` timestamp stays intact (important
 * for the "waiting > 3 days" age-breakdown in the admin digest).
 *
 * Inside the per-loan transaction we RE-CHECK the NULL condition under
 * lockForUpdate — protects against two parallel manual runs trying to
 * flag the same loan simultaneously (TOCTOU).
 *
 * -------------------------------------------------------------------------
 * LOAN-EVENT (per Q22)
 *
 * Each newly-flagged loan gets ONE `buyback_triggered` LoanEvent with
 * metadata (per client guidance including `originator_id`):
 *   {
 *     eligible_at,
 *     days_since_became_late,
 *     calculated_buyback_amount_at_detection,
 *     coverage_type,
 *     originator_id,
 *   }
 *
 * status_pair CHECK: `buyback_triggered` is a pure decision event — NO
 * loan status transition happens here, only `buyback_eligible_at` is
 * stamped. `from_status` and `to_status` are both NULL (the both-null
 * branch of `chk_loan_events_status_pair`). ✅ compatible.
 *
 * triggered_by_consistency CHECK: cron is the actor → triggered_by =
 * 'system', triggered_by_user_id = NULL. ✅ compatible.
 *
 * -------------------------------------------------------------------------
 * ADMIN DIGEST (per Q21)
 *
 * One digest notification per cron run, sent to every User::where('role',
 * 'admin'). The digest surfaces:
 *   - `newly_eligible_count`  loans flagged in THIS run
 *   - `waiting_more_than_3_days_count`  loans flagged > 3 days ago AND
 *                                        still pending (not dismissed,
 *                                        not bought back)
 *   - `loan_ids`  IDs of the newly-flagged loans (for email listing)
 *
 * Skip condition: if BOTH counts are 0, NO digest is dispatched — no
 * empty daily spam per client instruction.
 *
 * -------------------------------------------------------------------------
 * METRICS (F1 naming convention per client instruction)
 *
 *   last_buyback_check_run_at
 *   last_buyback_check_status        — success | failure | disabled | dry_run
 *   last_buyback_check_loans_newly_eligible
 *   last_buyback_check_notifications_queued
 *   last_buyback_check_enabled       — mirror of the platform setting for
 *                                      single-pane /api/health/scheduler
 *
 * Written via PlatformMetric::record() at the END of a run, except on
 * dry-run where they are logged but not persisted.
 */
class DetectBuybackEligible extends Command
{
    protected $signature = 'loans:detect-buyback-eligible
        {--dry-run : Truly read-only — rolls back at the end, no writes anywhere}
        {--loan= : Process only this loan id (debug)}
        {--detail : Per-loan progress logging}
        {--force : Bypass the buyback_check_enabled platform setting}';

    protected $description = 'Detect loans past their originator buyback trigger, flag them, notify admin.';

    private const LOCK_KEY = 'loans:detect-buyback-eligible';
    private const LOCK_TTL_SECONDS = 600;
    private const WAITING_THRESHOLD_DAYS = 3;

    public function handle(
        BuybackEligibilityService $eligibility,
        BuybackCalculationService $calculator,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $force = (bool) $this->option('force');
        $loanIdsFilter = $loanId !== null ? [$loanId] : null;

        $this->info(sprintf(
            'loans:detect-buyback-eligible starting (dry-run=%s, loan=%s, force=%s)',
            $dryRun ? 'YES' : 'no',
            $loanId !== null ? $loanId : '(all)',
            $force ? 'YES' : 'no',
        ));

        // Kill switch — mirror F1 late_check_enabled. --force bypasses.
        if (! $force && ! PlatformSetting::get('buyback_check_enabled', true)) {
            $this->warn('buyback_check_enabled = false. Skipping. Use --force to override.');
            if (! $dryRun) {
                $this->writeMetrics([
                    'last_buyback_check_run_at' => now()->toIso8601String(),
                    'last_buyback_check_status' => 'disabled',
                    'last_buyback_check_enabled' => 'false',
                ]);
            }
            return self::SUCCESS;
        }

        // Cache lock — mirror of F1. Prevents two manual runs from racing.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            $this->error('Another loans:detect-buyback-eligible is already running. Exit.');
            return self::FAILURE;
        }

        try {
            return $this->runWithDryRunWrapper($dryRun, fn () =>
                $this->runWork($eligibility, $calculator, $loanIdsFilter, $dryRun)
            );
        } catch (Throwable $e) {
            Log::error('loans:detect-buyback-eligible failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Failed: ' . $e->getMessage());
            if (! $dryRun) {
                $this->writeMetrics([
                    'last_buyback_check_run_at' => now()->toIso8601String(),
                    'last_buyback_check_status' => 'failure',
                ]);
            }
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Outer DB transaction that rolls back at the end of a dry-run. Inner
     * services' own DB::transaction() calls become savepoints — same F1
     * pattern. No state persists from a dry-run: no flagged timestamps,
     * no events, no metrics, no admin emails.
     */
    private function runWithDryRunWrapper(bool $dryRun, \Closure $work): int
    {
        if (! $dryRun) {
            return $work();
        }
        DB::beginTransaction();
        try {
            return $work();
        } finally {
            DB::rollBack();
            $this->info('[DRY-RUN] All writes rolled back. DB unchanged.');
        }
    }

    private function runWork(
        BuybackEligibilityService $eligibility,
        BuybackCalculationService $calculator,
        ?array $loanIdsFilter,
        bool $dryRun,
    ): int {
        $runAt = now();
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $verbose = (bool) $this->option('detail');

        // 1. Detection via service. WHERE clause already includes
        //    buyback_eligible_at IS NULL (idempotency); the service won't
        //    re-surface already-flagged loans.
        $eligible = $eligibility->detectNewlyEligible($today, $loanIdsFilter);
        $this->line(sprintf('  detected %d newly-eligible loan(s)', $eligible->count()));

        // 2. Flag + emit loan_event per loan.
        $newlyFlaggedIds = [];
        foreach ($eligible as $loan) {
            $calc = $calculator->calculateTotal($loan);
            $daysSinceLate = $this->daysBetween($loan->became_late_at, $today);
            $wasFlagged = $this->flagLoanAtomically($loan, $calc, $daysSinceLate, $runAt);

            if ($wasFlagged) {
                $newlyFlaggedIds[] = $loan->id;
                if ($verbose) {
                    $this->line(sprintf(
                        '    loan #%d flagged: %s € (%s), %d d late',
                        $loan->id, $calc->total, $calc->coverageType, $daysSinceLate,
                    ));
                }
            } elseif ($verbose) {
                $this->line("    loan #{$loan->id}: already flagged — skipped (TOCTOU)");
            }
        }
        $newlyCount = count($newlyFlaggedIds);

        // 3. Count pending loans that are aging > 3 days — for the admin
        //    digest's age breakdown. Runs AFTER flagging so newly-flagged
        //    loans (timestamp = today) cannot leak into the older bucket.
        $olderThreshold = $today->copy()->subDays(self::WAITING_THRESHOLD_DAYS);
        $olderCount = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('buyback_dismissed_at')
            ->whereNull('bought_back_at')
            ->where('buyback_eligible_at', '<', $olderThreshold)
            ->count();

        $this->line(sprintf(
            '  age breakdown: %d newly eligible, %d waiting > %d days',
            $newlyCount, $olderCount, self::WAITING_THRESHOLD_DAYS,
        ));

        // 4. Admin digest dispatch.
        //    Skip entirely when BOTH counts are zero — no empty daily
        //    spam (Q21). Digest is sent iff there's actionable info.
        $notificationsQueued = 0;
        if ($newlyCount === 0 && $olderCount === 0) {
            $this->info('No actionable items (0 new, 0 > 3 days). Skipping admin digest.');
        } else {
            $admins = User::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                if ($dryRun) {
                    Log::info(sprintf(
                        '[DRY-RUN] Would notify admin #%d: %d newly eligible, %d waiting > %d days',
                        $admin->id, $newlyCount, $olderCount, self::WAITING_THRESHOLD_DAYS,
                    ));
                    continue;
                }

                $admin->notify(new BuybackEligibleAdminNotification(
                    newlyEligibleCount:        $newlyCount,
                    waitingMoreThan3DaysCount: $olderCount,
                    loanIds:                   $newlyFlaggedIds,
                    runAt:                     $runAt,
                ));
                $notificationsQueued++;
            }
            $this->line(sprintf(
                '  notifications: %d %s',
                $notificationsQueued,
                $dryRun ? 'logged (dry-run)' : 'queued',
            ));
        }

        // 5. Metrics.
        $stats = [
            'last_buyback_check_run_at' => $runAt->toIso8601String(),
            'last_buyback_check_status' => $dryRun ? 'dry_run' : 'success',
            'last_buyback_check_loans_newly_eligible' => (string) $newlyCount,
            'last_buyback_check_notifications_queued' => (string) $notificationsQueued,
            'last_buyback_check_enabled' => PlatformSetting::get('buyback_check_enabled', true) ? 'true' : 'false',
        ];
        if (! $dryRun) {
            $this->writeMetrics($stats);
        } else {
            $this->info('[DRY-RUN] Skipping metric writes. Would record: ' . json_encode($stats));
        }

        Log::info('loans:detect-buyback-eligible completed', $stats + [
            'older_count' => $olderCount,
            'loan_ids' => $newlyFlaggedIds,
        ]);
        $this->info('Done.');
        return self::SUCCESS;
    }

    /**
     * Atomic flag + event write. Re-checks idempotency under row lock so
     * two parallel manual runs can't double-flag the same loan.
     *
     * Returns true iff we wrote buyback_eligible_at + loan_event.
     */
    private function flagLoanAtomically(
        Loan $loan,
        \App\Services\Loans\BuybackCalculation $calc,
        int $daysSinceLate,
        Carbon $runAt,
    ): bool {
        return DB::transaction(function () use ($loan, $calc, $daysSinceLate, $runAt) {
            $locked = Loan::where('id', $loan->id)->lockForUpdate()->first();
            if (! $locked || $locked->buyback_eligible_at !== null) {
                return false; // already flagged by a racing run — skip
            }

            $locked->forceFill(['buyback_eligible_at' => $runAt])->save();

            LoanEvent::create([
                'loan_id'              => $locked->id,
                'event_type'           => LoanEvent::TYPE_BUYBACK_TRIGGERED,
                // Pure decision event — no status transition (both-null branch
                // of chk_loan_events_status_pair).
                'from_status'          => null,
                'to_status'            => null,
                'triggered_by'         => LoanEvent::TRIGGERED_BY_SYSTEM,
                'triggered_by_user_id' => null,
                'metadata' => [
                    'eligible_at' => $runAt->toIso8601String(),
                    'days_since_became_late' => $daysSinceLate,
                    'calculated_buyback_amount_at_detection' => $calc->total,
                    'coverage_type' => $calc->coverageType,
                    'originator_id' => $locked->originator_id,
                ],
                'occurred_at' => $runAt,
            ]);

            return true;
        });
    }

    /**
     * Inclusive day count from $becameLateAt to $today. Mirror of F1's
     * LateDetectionService::daysBetween — normalised to start-of-day to
     * sidestep time-of-day jitter.
     */
    private function daysBetween(\Carbon\CarbonInterface $becameLateAt, Carbon $today): int
    {
        $due = $becameLateAt->copy()->startOfDay();
        $now = $today->copy()->startOfDay();
        if ($due->greaterThanOrEqualTo($now)) {
            return 0;
        }
        return (int) abs($due->diffInDays($now));
    }

    private function writeMetrics(array $stats): void
    {
        foreach ($stats as $key => $value) {
            PlatformMetric::record($key, (string) $value);
        }
    }
}
