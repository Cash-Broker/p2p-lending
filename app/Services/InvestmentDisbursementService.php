<?php

namespace App\Services;

use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Notifications\RepaymentReceivedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Pays investors of an offer-based loan per their OWN investment_schedule —
 * the differential-payout counterpart to RepaymentService (which splits one
 * borrower payment flat pro-rata across all investors of a legacy loan).
 *
 * Each installment credits the investor exactly what their offer promised:
 * amortizing investors get principal+interest monthly, interest-only investors
 * get interest monthly (principal at maturity), capitalized investors get one
 * lump at maturity. Same wallet mechanics as RepaymentService
 * (repayPrincipal / repayInterest), driven per-investment instead of pro-rata.
 */
class InvestmentDisbursementService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Credit every pending/late installment for $loanId that is due on or
     * before $asOf (default: now), per investment. Money first, notifications
     * after commit — a failed email must never roll back a payout.
     *
     * @return array{paid_count: int, total_paid: string}
     */
    public function disburseDue(int $loanId, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $notificationQueue = [];
        $paidCount = 0;
        $totalPaid = '0.00';

        DB::transaction(function () use ($loanId, $asOf, &$notificationQueue, &$paidCount, &$totalPaid) {
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_LATE], true)) {
                throw new InvalidArgumentException('Disbursement can only be processed for active or late loans.');
            }

            $rows = InvestmentSchedule::where('loan_id', $loanId)
                ->whereIn('status', ['pending', 'late'])
                ->whereDate('due_date', '<=', $asOf->toDateString())
                ->with('investment.user')
                ->lockForUpdate()
                ->orderBy('due_date')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $investment = $row->investment;
                $reference = "loan:{$loanId}:investment:{$investment->id}:schedule:{$row->id}";

                if (bccomp((string) $row->principal, '0', 2) > 0) {
                    $this->walletService->repayPrincipal(
                        $investment->user_id,
                        (string) $row->principal,
                        "Principal payout for loan #{$loanId}",
                        $reference,
                    );
                }

                if (bccomp((string) $row->interest, '0', 2) > 0) {
                    $this->walletService->repayInterest(
                        $investment->user_id,
                        (string) $row->interest,
                        "Interest payout for loan #{$loanId}",
                        $reference,
                    );
                }

                $row->update(['status' => 'paid', 'paid_at' => now()]);
                $paidCount++;
                $totalPaid = bcadd($totalPaid, (string) $row->total, 2);

                $notificationQueue[] = [
                    'user' => $investment->user,
                    'loan_id' => $loanId,
                    'principal' => (string) $row->principal,
                    'interest' => (string) $row->interest,
                ];
            }
        });

        foreach ($notificationQueue as $item) {
            try {
                $item['user']->notify(new RepaymentReceivedNotification(
                    $item['loan_id'],
                    $item['principal'],
                    $item['interest'],
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send investment disbursement notification', [
                    'user_id' => $item['user']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['paid_count' => $paidCount, 'total_paid' => $totalPaid];
    }
}
