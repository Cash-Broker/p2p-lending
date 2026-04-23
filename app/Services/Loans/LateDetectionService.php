<?php

namespace App\Services\Loans;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PlatformSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detects past-grace-period schedule items and marks them late.
 *
 * Two responsibilities, two methods:
 *   1. detectNewlyLateSchedules — finds schedules that crossed the grace
 *      threshold today and stamps them status='late', became_late_at, days_late.
 *      Returns the collection so callers can do downstream work
 *      (LoanStatusUpdater, notifications).
 *   2. refreshDaysLateSnapshots — increments days_late on schedules that
 *      were already late on a prior day. Snapshot strategy (per approved
 *      decision #3 in F1 discovery): the column is a sortable, indexable,
 *      audit-friendly value at command-run time. "Live current days late"
 *      is also computable as `today - due_date` for callers who need it.
 *
 * Idempotency: running either method twice on the same day produces no
 * additional writes (status='pending' filter excludes already-late rows;
 * days_late update only fires when value changed).
 *
 * Concurrency: schedule rows are lockForUpdate within a per-loan transaction
 * so a parallel RepaymentService run cannot mark something paid mid-detection.
 *
 * Timezone: all date math uses config('app.timezone') so "today" matches the
 * server's operating timezone (Europe/Sofia per ops convention — see CLAUDE.md
 * Step 8 docs).
 */
class LateDetectionService
{
    /**
     * Scan loans in [active, late] for schedules that have crossed
     * (today - grace_period_days) days past due_date and aren't already
     * marked late. Mark them, return the collection of NEWLY-late
     * schedules (eager-loaded with their loan).
     *
     * @param  ?array<int>  $loanIdsFilter  optional restriction (used by `--loan=ID` debug flag)
     */
    public function detectNewlyLateSchedules(?Carbon $today = null, ?array $loanIdsFilter = null): Collection
    {
        $today = $today ? $today->copy()->startOfDay() : Carbon::now(config('app.timezone'))->startOfDay();
        $gracePeriodDays = (int) PlatformSetting::get('grace_period_days', 10);
        $threshold = $today->copy()->subDays($gracePeriodDays);

        $newlyLate = collect();

        // Iterate per-loan so each loan's schedules get their own short
        // transaction — keeps locks small under contention.
        $loanQ = Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE]);
        if ($loanIdsFilter !== null) {
            $loanQ->whereIn('id', $loanIdsFilter);
        }
        $loanIds = $loanQ->pluck('id');

        foreach ($loanIds as $loanId) {
            $found = DB::transaction(function () use ($loanId, $today, $threshold) {
                $schedules = AmortizationSchedule::where('loan_id', $loanId)
                    ->where('status', 'pending')
                    ->whereDate('due_date', '<=', $threshold->toDateString())
                    ->lockForUpdate()
                    ->get();

                $marked = collect();
                foreach ($schedules as $schedule) {
                    $daysLate = $this->daysBetween($schedule->due_date, $today);
                    $schedule->forceFill([
                        'status' => 'late',
                        'became_late_at' => now(),
                        'days_late' => $daysLate,
                    ])->save();
                    $marked->push($schedule);
                }
                return $marked;
            });

            if ($found->isNotEmpty()) {
                $newlyLate = $newlyLate->merge($found);
            }
        }

        return $newlyLate;
    }

    /**
     * Refresh days_late on all currently-late schedules. Skips writes when
     * the snapshot value is unchanged (idempotent, low-write-traffic).
     *
     * Returns count of rows that actually got updated.
     */
    public function refreshDaysLateSnapshots(?Carbon $today = null): int
    {
        $today = $today ? $today->copy()->startOfDay() : Carbon::now(config('app.timezone'))->startOfDay();
        $updated = 0;

        AmortizationSchedule::where('status', 'late')
            ->chunkById(200, function ($schedules) use ($today, &$updated) {
                foreach ($schedules as $schedule) {
                    $daysLate = $this->daysBetween($schedule->due_date, $today);
                    if ((int) $schedule->days_late !== $daysLate) {
                        $schedule->forceFill(['days_late' => $daysLate])->save();
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    /**
     * Inclusive day count from $dueDate to $today, never negative.
     * Both inputs are normalised to start-of-day to avoid time-of-day jitter.
     */
    private function daysBetween(Carbon $dueDate, Carbon $today): int
    {
        $due = $dueDate->copy()->startOfDay();
        $now = $today->copy()->startOfDay();
        if ($due->greaterThanOrEqualTo($now)) {
            return 0;
        }
        return (int) abs($due->diffInDays($now));
    }
}
