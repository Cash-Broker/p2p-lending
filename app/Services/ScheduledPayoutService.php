<?php

namespace App\Services;

use App\Models\Loan;
use App\Services\Loans\LoanStatusUpdaterService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a loan's scheduled payout to the right engine and drives the
 * automatic (cron) run. The SAME entry point backs the manual admin button and
 * the timer — the only difference is who calls it (Loan::payout_mode).
 *
 *   • offer-based loans → PayoutAccrualService (per-investor, per-plan accrual)
 *   • legacy loans      → post each due amortization installment via
 *                         RepaymentService (pro-rata to all investors)
 */
class ScheduledPayoutService
{
    public function __construct(
        private PayoutAccrualService $accrual,
        private RepaymentService $repayment,
        private LoanStatusUpdaterService $statusUpdater,
    ) {}

    /**
     * Run the due payout for ONE loan, dispatching by structure.
     *
     * @return array<string, mixed>
     */
    public function runForLoan(Loan $loan, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();

        if ($loan->usesOffers()) {
            $result = ['type' => 'offer'] + $this->accrual->processLoan($loan->id, $asOf);

            // PAY-30 (owner 2026-09-03): a partially funded loan whose last
            // investor row was just paid must not stay investable until the
            // 03:30 sweep of the next night — close it right here.
            if ($loan->fresh()->status === Loan::STATUS_FUNDING) {
                $result['auto_repaid'] = $this->statusUpdater->autoRepayLoanIfComplete($loan->id);
            }

            return $result;
        }

        // Legacy world keeps the pre-2026-08-13 scope: «олихвяването тръгва
        // от инвестицията» was implemented for the OFFER product; the legacy
        // engine (RepaymentService) still — correctly — refuses non-active
        // loans, so the dispatcher must not feed it funding-stage legacy
        // loans (they would throw every night). Closed historical set.
        if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_LATE], true)) {
            return ['type' => 'legacy', 'posted_count' => 0];
        }

        // Legacy: post every amortization installment due on/before $asOf.
        $rows = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'late'])
            ->whereDate('due_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $posted = 0;
        foreach ($rows as $row) {
            $this->repayment->processRepayment($loan->id, $row->id);
            $posted++;
        }

        return ['type' => 'legacy', 'posted_count' => $posted];
    }

    /**
     * Run due payouts for EVERY payout-eligible loan in automatic mode
     * (incl. funding-stage loans since 2026-08-13 — interest runs from the
     * invest moment). One loan's failure is logged and skipped — it must not
     * stop the rest of the batch.
     *
     * Paused loans (PAY-13) are counted, not failed — they must not trip the
     * PayoutRunFailedAdminNotification.
     *
     * @return array{loans_processed:int, loans_failed:int, loans_paused:int, failed_loan_ids:array<int,int>}
     */
    public function runAllAutomatic(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $processed = 0;
        $failed = 0;
        $paused = 0;
        $failedLoanIds = [];

        Loan::query()
            ->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES)
            ->where('payout_mode', Loan::PAYOUT_MODE_AUTOMATIC)
            ->orderBy('id')
            ->chunkById(100, function ($loans) use ($asOf, &$processed, &$failed, &$paused, &$failedLoanIds) {
                foreach ($loans as $loan) {
                    try {
                        $result = $this->runForLoan($loan, $asOf);
                        if (($result['paused'] ?? false) === true) {
                            $paused++;
                        }
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $failedLoanIds[] = (int) $loan->id;
                        Log::error('Scheduled payout failed for loan', [
                            'loan_id' => $loan->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['loans_processed' => $processed, 'loans_failed' => $failed, 'loans_paused' => $paused, 'failed_loan_ids' => $failedLoanIds];
    }
}
