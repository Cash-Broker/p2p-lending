<?php

namespace App\Console\Commands\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformMetric;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\LoanWentLateNotification;
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
 *   last_late_check_recovery_skipped_default — int (loans skipped due to default schedules — manual review)
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

        // 3b. Phase 3 P3-F5 — auto-close cleanly-completing active loans
        // (never went late). Iterates status=active loans and transitions
        // any with all-paid schedules to `repaid`. Mirrors the late-recovery
        // rule R1 tiebreaker pattern. See DECISIONS.md P3-02.
        $autoRepay = $updater->autoRepayCompletedLoans($loanIdsFilter);
        $this->line(sprintf(
            '  auto-repaid: %d completed active loan(s)',
            count($autoRepay['auto_repaid']),
        ));
        if ($verbose) {
            foreach ($autoRepay['auto_repaid'] as $loanId) {
                $this->line("    loan #{$loanId} → repaid (all schedules paid)");
            }
        }

        // 4. Notifications — dispatch LoanWentLateNotification to every
        //    investor with a position in each newly-late loan.
        //
        //    Per Q2 (future-proof for secondary market): we read positions
        //    from the investments table at THIS moment of late transition,
        //    not a stale cached list. An investor with multiple positions
        //    in the same loan gets ONE notification with their summed amount.
        //
        //    Notifications are queued (LoanWentLateNotification implements
        //    ShouldQueue) so the command's wall-clock time stays bounded
        //    regardless of investor count.
        //
        //    The notification class' via() does its own per-(user, loan,
        //    became_late_at) deduplication so a manual --force re-run
        //    inside the same late period won't spam the inbox.
        //
        //    --dry-run: skipped entirely (the outer DB transaction would
        //    roll back the queue insert anyway, but logging "would notify"
        //    is clearer for debugging).
        $notificationsQueued = 0;
        foreach ($transitions['newly_late'] as $loanId) {
            $loan = Loan::find($loanId);
            if (! $loan) {
                continue;
            }

            // Snapshot from the just-written went_late event so the email's
            // "X days late" matches what was logged at transition time
            // (rather than today's value, which could differ if the queue
            // worker processes the job hours later).
            $event = LoanEvent::where('loan_id', $loanId)
                ->where('event_type', LoanEvent::TYPE_WENT_LATE)
                ->latest('id')
                ->first();
            $daysLateAtTransition = (int) ($event?->metadata['days_late_at_transition'] ?? 0);

            // Outstanding loan principal = scheduled total − already paid.
            $totalPrincipal = (string) $loan->amortizationSchedules()->sum('principal');
            $paidPrincipal = (string) $loan->amortizationSchedules()->where('status', 'paid')->sum('principal');
            $loanOutstandingPrincipal = bcsub($totalPrincipal, $paidPrincipal, 2);

            // Per-investor totals: sum amounts for investors with multiple
            // positions in this loan so each receives exactly one
            // notification with their aggregate.
            $investorTotals = $loan->investments()
                ->select('user_id', DB::raw('SUM(amount) as total_amount'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            if ($investorTotals->isEmpty()) {
                continue;
            }

            $users = User::whereIn('id', $investorTotals->keys())->get();
            $loanFundedAmount = (string) $loan->funded_amount;

            foreach ($users as $user) {
                $investorAmount = (string) $investorTotals[$user->id]->total_amount;

                // Investor's pro-rata share of the outstanding principal —
                // their slice of what the borrower still owes the loan as
                // a whole. bcdiv at scale 10 then rounded to 2 for display.
                if (bccomp($loanFundedAmount, '0', 2) > 0) {
                    $share = bcdiv($investorAmount, $loanFundedAmount, 10);
                    $investorOutstandingPrincipal = bcmul($loanOutstandingPrincipal, $share, 2);
                } else {
                    $investorOutstandingPrincipal = '0.00';
                }

                if ($dryRun) {
                    Log::info("[DRY-RUN] Would notify user #{$user->id} of loan #{$loanId} going late (investment={$investorAmount} EUR, outstanding={$investorOutstandingPrincipal} EUR)");
                } else {
                    $user->notify(new LoanWentLateNotification(
                        loan: $loan,
                        becameLateAt: $loan->became_late_at,
                        daysLateAtTransition: $daysLateAtTransition,
                        investorTotalAmount: $investorAmount,
                        investorOutstandingPrincipal: $investorOutstandingPrincipal,
                    ));
                }
                $notificationsQueued++;
            }
        }
        $this->line(sprintf('  notifications: %d %s', $notificationsQueued, $dryRun ? 'logged (dry-run)' : 'queued'));

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
            // P3-F5 — separate metric for auto-closed loans (completing cleanly
            // without going late). Ops can watch this over time to estimate
            // loan completion rate.
            'last_late_check_auto_repaid' => (string) count($autoRepay['auto_repaid']),
            // Surfaces the safeguard hit count so support sees it in the
            // dashboard without grepping logs (see LoanStatusUpdaterService).
            'last_late_check_recovery_skipped_default' => (string) count($transitions['recovery_skipped_default']),
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
