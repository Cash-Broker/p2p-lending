<?php

namespace App\Services;

use App\Models\Loan;
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
            return ['type' => 'offer'] + $this->accrual->processLoan($loan->id, $asOf);
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
     * Run due payouts for EVERY active/late loan in automatic mode. One loan's
     * failure is logged and skipped — it must not stop the rest of the batch.
     *
     * @return array{loans_processed:int, loans_failed:int}
     */
    public function runAllAutomatic(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $processed = 0;
        $failed = 0;

        Loan::query()
            ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE])
            ->where('payout_mode', Loan::PAYOUT_MODE_AUTOMATIC)
            ->orderBy('id')
            ->chunkById(100, function ($loans) use ($asOf, &$processed, &$failed) {
                foreach ($loans as $loan) {
                    try {
                        $this->runForLoan($loan, $asOf);
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::error('Scheduled payout failed for loan', [
                            'loan_id' => $loan->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['loans_processed' => $processed, 'loans_failed' => $failed];
    }
}
