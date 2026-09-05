<?php

namespace App\Services\Loans;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Desired-vs-actual reconciler for `loans.payouts_paused_at` — PAY-13.
 *
 * Reni's model («платформата плаща по график») stays the DEFAULT: the setting
 * `payout_pause_enabled` ships OFF. When it is on, a payout-eligible offer loan
 * whose OLDEST late borrower-tracker row went late ≥ `payout_pause_late_days`
 * ago gets stamped; PayoutAccrualService then pays nothing for it (the gate is
 * `Loan::isPayoutPaused()` = stamp AND setting, read under the loan lock).
 *
 * The clock is the ROW's became_late_at, not the loan's status: an accidental
 * admin `late → active` Select flip neither releases withheld money nor
 * restarts the clock (03:30 re-marks the loan anyway). The stamp is cleared —
 * with a `payouts_resumed` event — only for loans still payout-eligible;
 * default/terminal loans keep it silently, so no investor ever sees a false
 * «възобновени».
 *
 * Runs as step 0 of `loans:process-payouts` (04:00) — deliberately NOT behind
 * the `late_check_enabled` kill switch, so a resume never waits on it.
 * Idempotent; moves no money; every loan in its own short transaction.
 */
class PayoutPauseService
{
    public const SETTING_ENABLED = 'payout_pause_enabled';

    public const SETTING_LATE_DAYS = 'payout_pause_late_days';

    public const DEFAULT_LATE_DAYS = 30;

    public const KIND_PAUSED = 'payouts_paused';

    public const KIND_RESUMED = 'payouts_resumed';

    public static function isEnabled(): bool
    {
        return (bool) PlatformSetting::get(self::SETTING_ENABLED, false);
    }

    public static function thresholdDays(): int
    {
        return max(0, (int) PlatformSetting::get(self::SETTING_LATE_DAYS, self::DEFAULT_LATE_DAYS));
    }

    /**
     * One loan's failure (deadlock, lock wait, MySQL blip) is logged and collected
     * — it must never stop the other loans, nor the payout run that follows.
     *
     * @return array{paused: array<int, int>, resumed: array<int, int>, failed: array<int, int>}
     */
    public function reconcile(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();

        // Candidates: eligible loans with a late tracker row ∪ every stamped loan.
        $candidateIds = Loan::query()
            ->where(function ($q) {
                $q->where(function ($eligible) {
                    $eligible->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES)
                        ->whereHas('amortizationSchedules', fn ($s) => $s->borrowerTracker()->where('status', 'late'));
                })->orWhereNotNull('payouts_paused_at');
            })
            ->orderBy('id')
            ->pluck('id');

        $result = ['paused' => [], 'resumed' => [], 'failed' => []];

        foreach ($candidateIds as $loanId) {
            try {
                $outcome = DB::transaction(function () use ($loanId, $today): ?string {
                    $loan = Loan::whereKey($loanId)->lockForUpdate()->first();

                    return $loan === null ? null : $this->reconcileLoan($loan, $today);
                });
            } catch (Throwable $e) {
                $result['failed'][] = (int) $loanId;
                Log::error('Payout pause reconcile failed for loan', ['loan_id' => $loanId, 'error' => $e->getMessage()]);

                continue;
            }

            if ($outcome === 'paused') {
                $result['paused'][] = (int) $loanId;
            } elseif ($outcome === 'resumed') {
                $result['resumed'][] = (int) $loanId;
            }
        }

        return $result;
    }

    public function shouldBePaused(Loan $loan, CarbonInterface $today): bool
    {
        if (! self::isEnabled() || ! in_array($loan->status, Loan::PAYOUT_ELIGIBLE_STATUSES, true) || ! $loan->usesOffers()) {
            return false;
        }

        $oldest = $loan->amortizationSchedules()->borrowerTracker()->where('status', 'late')->min('became_late_at');
        if ($oldest === null) {
            return false;
        }

        $cutoff = CarbonImmutable::instance($today)->startOfDay()->subDays(self::thresholdDays());

        return CarbonImmutable::parse($oldest)->startOfDay()->lessThanOrEqualTo($cutoff);
    }

    /**
     * Caller holds the loan row lock.
     *
     * @return 'paused'|'resumed'|null
     */
    public function reconcileLoan(Loan $locked, CarbonInterface $today): ?string
    {
        $desired = $this->shouldBePaused($locked, $today);

        if ($desired && $locked->payouts_paused_at === null) {
            $lateRows = $locked->amortizationSchedules()->borrowerTracker()->where('status', 'late')->get(['days_late', 'became_late_at']);
            $oldest = $lateRows->min('became_late_at');

            $locked->forceFill(['payouts_paused_at' => now()])->save();

            // Both statuses NULL — the branch chk_loan_events_status_pair allows
            // for non-transition events; metadata.kind tells consumers what it is.
            LoanEvent::create([
                'loan_id' => $locked->id,
                'event_type' => LoanEvent::TYPE_STATUS_CHANGED,
                'from_status' => null,
                'to_status' => null,
                'triggered_by' => LoanEvent::TRIGGERED_BY_SYSTEM,
                'triggered_by_user_id' => null,
                'metadata' => [
                    'kind' => self::KIND_PAUSED,
                    'threshold_days' => self::thresholdDays(),
                    'days_late' => (int) ($lateRows->max('days_late') ?? 0),
                    'late_borrower_rows' => $lateRows->count(),
                    'oldest_late_since' => $oldest !== null ? CarbonImmutable::parse($oldest)->toIso8601String() : null,
                ],
                'occurred_at' => now(),
            ]);

            return 'paused';
        }

        if (! $desired && $locked->payouts_paused_at !== null && in_array($locked->status, Loan::PAYOUT_ELIGIBLE_STATUSES, true)) {
            $pausedSince = $locked->payouts_paused_at;
            $locked->forceFill(['payouts_paused_at' => null])->save();

            LoanEvent::create([
                'loan_id' => $locked->id,
                'event_type' => LoanEvent::TYPE_STATUS_CHANGED,
                'from_status' => null,
                'to_status' => null,
                'triggered_by' => LoanEvent::TRIGGERED_BY_SYSTEM,
                'triggered_by_user_id' => null,
                'metadata' => [
                    'kind' => self::KIND_RESUMED,
                    'resume_reason' => self::isEnabled() ? 'borrower_rows_settled' : 'setting_disabled',
                    'paused_since' => $pausedSince?->toIso8601String(),
                ],
                'occurred_at' => now(),
            ]);

            return 'resumed';
        }

        return null;
    }
}
