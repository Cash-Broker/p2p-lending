<?php

namespace App\Console\Commands\Loans;

use App\Models\Loan;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Services\Loans\LateDetectionService;
use App\Services\Loans\LoanStatusUpdaterService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily late-loan automation. Orchestrates LateDetectionService +
 * LoanStatusUpdaterService and records observability metrics.
 *
 * Scheduled at 03:30 (after `ledger:reconcile` at 03:00) by
 * bootstrap/app.php. Also runnable manually for support / debugging.
 *
 * Flags:
 *   --dry-run        Truly read-only. Wraps the whole run in a DB
 *                    transaction that is rolled back at the end —
 *                    no schedule writes, no loan transitions, no
 *                    loan_events, no metrics. Notifications are
 *                    logged-only as `[DRY-RUN]` (would-have).
 *   --loan=ID        Process only this loan. Useful for reproducing
 *                    a support ticket. Combine with --dry-run for
 *                    diagnosis without side effects.
 *   --detail        Per-loan progress lines on stdout.
 *   --force          Bypass the `late_check_enabled` setting.
 *                    Required only if ops have paused automation
 *                    (rare — mostly during data fix-ups).
 *
 * Concurrency: Cache::lock 'loans:process-late' (10 min TTL) prevents
 * two manual invocations from overlapping. Scheduler uses
 * ->withoutOverlapping() at the cron level (file lock); the cache
 * lock is the belt to that suspenders for `php artisan` runs.
 *
 * Health: writes to `platform_metrics`:
 *   last_late_check_run_at         — Iso-8601 timestamp
 *   last_late_check_status         — success | failure | disabled | dry_run
 *   last_late_check_loans_scanned  — int
 *   last_late_check_schedules_marked — int (newly-late)
 *   last_late_check_loans_to_late  — int
 *   last_late_check_loans_recovered — int (active + repaid combined)
 *   last_late_check_notifications_queued — int
 *
 * Read these via /api/health/scheduler for external monitoring.
 */
class ProcessLateLoans extends Command
{
    protected $signature = 'loans:process-late
        {--dry-run : Truly read-only — rolls back at the end, no writes anywhere}
        {--loan= : Process only this loan id (debug)}
        {--detail : Per-loan progress logging (renamed from --verbose because that is reserved by Laravel)}
        {--force : Bypass the late_check_enabled platform setting}';

    protected $description = 'Detect newly-late schedule items, transition loan statuses, dispatch notifications';

    private const LOCK_KEY = 'loans:process-late';
    private const LOCK_TTL_SECONDS = 600;

    public function handle(LateDetectionService $detection, LoanStatusUpdaterService $updater): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $force = (bool) $this->option('force');
        $loanIdsFilter = $loanId !== null ? [$loanId] : null;

        $this->info(sprintf(
            'loans:process-late starting (dry-run=%s, loan=%s, force=%s)',
            $dryRun ? 'YES' : 'no',
            $loanId !== null ? $loanId : '(all)',
            $force ? 'YES' : 'no',
        ));

        // Enabled check — bypassed by --force
        if (! $force && ! PlatformSetting::get('late_check_enabled', true)) {
            $this->warn('late_check_enabled = false. Skipping. Use --force to override.');
            if (! $dryRun) {
                $this->writeMetrics(['last_late_check_status' => 'disabled']);
            }
            return self::SUCCESS;
        }

        // Concurrency lock (manual-run safety; scheduler also has withoutOverlapping)
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            $this->error('Another loans:process-late instance is already running. Exit.');
            return self::FAILURE;
        }

        try {
            return $this->runWithDryRunWrapper($dryRun, function () use ($detection, $updater, $loanIdsFilter, $dryRun) {
                return $this->runWork($detection, $updater, $loanIdsFilter, $dryRun);
            });
        } catch (Throwable $e) {
            Log::error('loans:process-late failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Failed: ' . $e->getMessage());
            if (! $dryRun) {
                $this->writeMetrics(['last_late_check_status' => 'failure']);
            }
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * If dry-run, wrap inner work in a transaction we always roll back so
     * NOTHING persists — including writes the inner services do via their
     * own `DB::transaction()` calls (those become savepoints inside our
     * outer transaction). The closure's return value bubbles out.
     */
    private function runWithDryRunWrapper(bool $dryRun, \Closure $work): int
    {
        if (! $dryRun) {
            return $work();
        }

        DB::beginTransaction();
        try {
            $exitCode = $work();
            return $exitCode;
        } finally {
            DB::rollBack();
            $this->info('[DRY-RUN] All writes rolled back. DB unchanged.');
        }
    }

    private function runWork(
        LateDetectionService $detection,
        LoanStatusUpdaterService $updater,
        ?array $loanIdsFilter,
        bool $dryRun,
    ): int {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $verbose = (bool) $this->option('detail');

        // 1. Detection
        $newlyLateSchedules = $detection->detectNewlyLateSchedules($today, $loanIdsFilter);
        $this->line(sprintf('  detected %d newly-late schedule(s)', $newlyLateSchedules->count()));

        if ($verbose) {
            foreach ($newlyLateSchedules as $s) {
                $this->line(sprintf(
                    '    schedule #%d (loan #%d): due %s, %d days late',
                    $s->id, $s->loan_id, $s->due_date->toDateString(), $s->days_late
                ));
            }
        }

        // 2. Refresh days_late snapshots on already-late schedules
        $snapshotsUpdated = $detection->refreshDaysLateSnapshots($today);
        $this->line(sprintf('  refreshed %d days_late snapshot(s)', $snapshotsUpdated));

        // 3. Loan-level status transitions
        $transitions = $updater->transitionLoansAfterLateCheck($loanIdsFilter);
        $this->line(sprintf(
            '  transitions: %d → late, %d recovered to active, %d recovered to repaid, %d skipped (default schedules)',
            count($transitions['newly_late']),
            count($transitions['recovered_to_active']),
            count($transitions['recovered_to_repaid']),
            count($transitions['recovery_skipped_default']),
        ));

        if ($verbose) {
            foreach (['newly_late' => 'late', 'recovered_to_active' => 'active', 'recovered_to_repaid' => 'repaid', 'recovery_skipped_default' => 'skipped/default'] as $bucket => $label) {
                foreach ($transitions[$bucket] as $loanId) {
                    $this->line("    loan #{$loanId} → {$label}");
                }
            }
        }

        // 4. Notifications (placeholder — wired in Step 6)
        $notificationsQueued = 0;
        foreach ($transitions['newly_late'] as $loanId) {
            $loan = Loan::find($loanId);
            if (! $loan) {
                continue;
            }
            // TODO Step 6: dispatch real notifications.
            //   - Per Q2: notify investors with ACTIVE position in this loan
            //     at the moment of late transition (future-proof for secondary
            //     market).
            //   - Use LoanWentLateNotification (Notification class to be added in Step 6).
            //   - Idempotency: don't queue twice for same loan-day.
            $investors = $loan->investments()
                ->select('user_id')
                ->distinct()
                ->pluck('user_id');
            $count = $investors->count();
            $notificationsQueued += $count;
            Log::info(sprintf(
                '%sWould notify %d investor(s) of loan #%d going late',
                $dryRun ? '[DRY-RUN] ' : '[F1-PLACEHOLDER] ',
                $count,
                $loanId,
            ));
        }
        $this->line(sprintf('  notifications: %d queued (placeholder — Step 6)', $notificationsQueued));

        // 5. Stamp loans.last_late_check_at on every scanned loan (skipped in dry-run
        //    since the wrapper would roll it back anyway).
        if (! $dryRun) {
            $loanQ = Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE]);
            if ($loanIdsFilter !== null) {
                $loanQ->whereIn('id', $loanIdsFilter);
            }
            $scanned = $loanQ->update(['last_late_check_at' => now()]);
        } else {
            $scanned = Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE])
                ->when($loanIdsFilter, fn ($q, $f) => $q->whereIn('id', $f))
                ->count();
        }

        // 6. Metrics — skipped in dry-run.
        $stats = [
            'last_late_check_run_at' => now()->toIso8601String(),
            'last_late_check_status' => $dryRun ? 'dry_run' : 'success',
            'last_late_check_loans_scanned' => (string) $scanned,
            'last_late_check_schedules_marked' => (string) $newlyLateSchedules->count(),
            'last_late_check_loans_to_late' => (string) count($transitions['newly_late']),
            'last_late_check_loans_recovered' => (string) (count($transitions['recovered_to_active']) + count($transitions['recovered_to_repaid'])),
            'last_late_check_notifications_queued' => (string) $notificationsQueued,
        ];
        if (! $dryRun) {
            $this->writeMetrics($stats);
        } else {
            $this->info('[DRY-RUN] Skipping metric writes. Would record: ' . json_encode($stats));
        }

        Log::info('loans:process-late completed', $stats);
        $this->info('Done.');
        return self::SUCCESS;
    }

    /**
     * Upsert a batch of metrics. last_late_check_run_at is set with the
     * current `measured_at` automatically by PlatformMetric::record.
     */
    private function writeMetrics(array $stats): void
    {
        foreach ($stats as $key => $value) {
            PlatformMetric::record($key, (string) $value);
        }
    }
}
