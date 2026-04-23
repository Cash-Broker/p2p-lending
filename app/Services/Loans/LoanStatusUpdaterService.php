<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transitions loan statuses based on schedule state, after LateDetectionService
 * has run.
 *
 * Two transitions handled:
 *
 *   active → late
 *     Triggered when: loan.status='active' AND ≥1 schedule with status='late'.
 *     Writes loan_event(went_late, active→late, system).
 *
 *   late → active  (recovery)  OR  late → repaid  (recovery + tiebreaker)
 *     Triggered when ALL of:
 *       (R1) No schedules currently have status='late'
 *       (R1) For every schedule that was previously late
 *            (became_late_at IS NOT NULL), paid_at IS NOT NULL AND
 *            paid_at >= became_late_at — i.e. the late items got paid AFTER
 *            becoming late. Schedules paid before becoming late (data fix-ups
 *            via admin) DO NOT count as recovery.
 *     Tiebreaker: if total_schedules == paid_schedules, transition is
 *       late → repaid (borrower paid remaining instalments in full at recovery
 *       moment). Otherwise late → active.
 *     Writes loan_event(recovered_from_late, late→{active|repaid}, system)
 *     with metadata: previous_became_late_at, paid_schedule_count,
 *     total_schedule_count, transitioned_to.
 *
 * Concurrency: each loan transition is in its own DB::transaction with
 * lockForUpdate on the loan row. A parallel run that races against this one
 * will block until commit, then re-check the status (idempotency).
 *
 * Note on `default`: F1 does not auto-transition loans to default
 * (admin-only via Filament). The state-machine permits late→default; F2 will
 * automate it. F1's recovery rule ignores schedules with status='default'
 * (admin handled them manually). If a loan has all 'late' cleared but some
 * 'default' present, recovery will currently fire — operations should be
 * aware. Documented as known limitation in CLAUDE.md (Step 8).
 */
class LoanStatusUpdaterService
{
    /**
     * Run after LateDetectionService. Returns the loan ids in each transition
     * bucket so the caller (the cron command) can dispatch notifications.
     *
     * @param  ?array<int>  $loanIdsFilter  optional restriction to a subset (used by `--loan=ID` debug flag)
     * @return array{newly_late: int[], recovered_to_active: int[], recovered_to_repaid: int[], recovery_skipped_default: int[]}
     */
    public function transitionLoansAfterLateCheck(?array $loanIdsFilter = null): array
    {
        $result = [
            'newly_late' => [],
            'recovered_to_active' => [],
            'recovered_to_repaid' => [],
            'recovery_skipped_default' => [],
        ];

        // active → late
        $activeQ = Loan::where('status', Loan::STATUS_ACTIVE)
            ->whereHas('amortizationSchedules', fn ($q) => $q->where('status', 'late'));
        if ($loanIdsFilter !== null) {
            $activeQ->whereIn('id', $loanIdsFilter);
        }

        foreach ($activeQ->pluck('id') as $loanId) {
            if ($this->markLoanLate($loanId)) {
                $result['newly_late'][] = $loanId;
            }
        }

        // late → active OR late → repaid (recovery)
        $lateQ = Loan::where('status', Loan::STATUS_LATE);
        if ($loanIdsFilter !== null) {
            $lateQ->whereIn('id', $loanIdsFilter);
        }

        foreach ($lateQ->pluck('id') as $loanId) {
            $newStatus = $this->maybeRecoverLoan($loanId, $result);
            if ($newStatus === Loan::STATUS_ACTIVE) {
                $result['recovered_to_active'][] = $loanId;
            } elseif ($newStatus === Loan::STATUS_REPAID) {
                $result['recovered_to_repaid'][] = $loanId;
            }
        }

        return $result;
    }

    /**
     * Transition a single active loan to late. Idempotent: if the loan is
     * not still active by the time we hold the lock, no-op.
     *
     * Returns true iff a transition + event happened.
     */
    private function markLoanLate(int $loanId): bool
    {
        return DB::transaction(function () use ($loanId) {
            $loan = Loan::lockForUpdate()->find($loanId);
            if (! $loan || $loan->status !== Loan::STATUS_ACTIVE) {
                return false;
            }

            $lateScheduleCount = $loan->amortizationSchedules()->where('status', 'late')->count();
            if ($lateScheduleCount === 0) {
                return false; // schedules paid between detection and update — nothing to do
            }

            $loan->transitionTo(Loan::STATUS_LATE);
            // Re-fetch to get updated status, then stamp became_late_at
            // (transitionTo did its own forceFill+save inside its own transaction,
            // but the attribute on $loan is already in sync because forceFill mutates).
            $loan->forceFill(['became_late_at' => now()])->save();

            LoanEvent::create([
                'loan_id' => $loanId,
                'event_type' => LoanEvent::TYPE_WENT_LATE,
                'from_status' => Loan::STATUS_ACTIVE,
                'to_status' => Loan::STATUS_LATE,
                'triggered_by' => LoanEvent::TRIGGERED_BY_SYSTEM,
                'triggered_by_user_id' => null,
                'metadata' => ['late_schedule_count' => $lateScheduleCount],
                'occurred_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Try to recover a single late loan. Returns the new status if recovery
     * happened (active or repaid), null if conditions are not met.
     *
     * @param  array  &$result  caller's result bucket — we append to
     *                          recovery_skipped_default when applicable so the
     *                          command can surface the count for ops review.
     */
    private function maybeRecoverLoan(int $loanId, array &$result): ?string
    {
        return DB::transaction(function () use ($loanId, &$result) {
            $loan = Loan::lockForUpdate()->find($loanId);
            if (! $loan || $loan->status !== Loan::STATUS_LATE) {
                return null;
            }

            // SAFEGUARD: loans with any 'default' schedule are out of scope for
            // F1 auto-recovery. F1 doesn't auto-mark schedules as default, but
            // an admin or a future phase (F2 buyback) could. If we silently
            // recovered such a loan we'd undo their decision — instead, skip
            // and warn for manual review.
            $hasDefault = $loan->amortizationSchedules()->where('status', 'default')->exists();
            if ($hasDefault) {
                Log::warning('loans:process-late skipped recovery of loan with default schedule items — manual review required', [
                    'loan_id' => $loanId,
                    'default_schedule_count' => $loan->amortizationSchedules()->where('status', 'default')->count(),
                ]);
                $result['recovery_skipped_default'][] = $loanId;
                return null;
            }

            // R1 part 0: only auto-recover loans we actually marked late.
            // If no schedule has ever had became_late_at set, the loan was
            // flipped to 'late' by admin (or seed data) for some reason
            // outside the automation's view — don't second-guess them.
            // Admin can manually transition back via Filament.
            $everHadLateSchedule = $loan->amortizationSchedules()
                ->whereNotNull('became_late_at')
                ->exists();
            if (! $everHadLateSchedule) {
                return null;
            }

            // R1 part 1: any schedule currently flagged late blocks recovery.
            $stillLate = $loan->amortizationSchedules()->where('status', 'late')->exists();
            if ($stillLate) {
                return null;
            }

            // R1 part 2: every previously-late schedule must have been paid
            // AFTER it became late. A row with became_late_at IS NOT NULL but
            // paid_at IS NULL OR paid_at < became_late_at is a data fix-up
            // (admin manually flipped status), not a real recovery — skip.
            $unrecoveredLate = $loan->amortizationSchedules()
                ->whereNotNull('became_late_at')
                ->where(function ($q) {
                    $q->whereNull('paid_at')
                        ->orWhereColumn('paid_at', '<', 'became_late_at');
                })
                ->exists();
            if ($unrecoveredLate) {
                return null;
            }

            $previousBecameLateAt = $loan->became_late_at;

            // Tiebreaker: all schedules paid → repaid; some pending → active.
            $totalSchedules = $loan->amortizationSchedules()->count();
            $paidSchedules = $loan->amortizationSchedules()->where('status', 'paid')->count();
            $newStatus = ($totalSchedules > 0 && $paidSchedules === $totalSchedules)
                ? Loan::STATUS_REPAID
                : Loan::STATUS_ACTIVE;

            $loan->transitionTo($newStatus);
            // Clear became_late_at on recovery (we preserved it in metadata below).
            $loan->forceFill(['became_late_at' => null])->save();

            LoanEvent::create([
                'loan_id' => $loanId,
                'event_type' => LoanEvent::TYPE_RECOVERED_FROM_LATE,
                'from_status' => Loan::STATUS_LATE,
                'to_status' => $newStatus,
                'triggered_by' => LoanEvent::TRIGGERED_BY_SYSTEM,
                'triggered_by_user_id' => null,
                'metadata' => [
                    // Audit trail per spec — we never lose the original timestamp.
                    'previous_became_late_at' => $previousBecameLateAt?->toIso8601String(),
                    'paid_schedule_count' => $paidSchedules,
                    'total_schedule_count' => $totalSchedules,
                    'transitioned_to' => $newStatus,
                ],
                'occurred_at' => now(),
            ]);

            return $newStatus;
        });
    }
}
