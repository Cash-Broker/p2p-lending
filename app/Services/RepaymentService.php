<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Notifications\RepaymentReceivedNotification;
use App\Services\Loans\InvestorDistributionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RepaymentService
{
    public function __construct(
        private WalletService $walletService,
        private InvestorDistributionService $distribution,
    ) {}

    /**
     * Post a scheduled repayment for ONE amortization installment.
     *
     * The installment id is REQUIRED and the per-investor principal/interest
     * are DERIVED from the locked schedule row — callers can no longer pass a
     * divergent figure (which previously minted unbacked balance) and the
     * 'paid' duplicate guard now runs on every call path (no null-id bypass).
     *
     * Principal distribution is EXACT per investor: the final unpaid
     * installment returns each investor's outstanding capital so
     * `Σ(principal returned to a user) == their invested` with zero drift;
     * non-final installments split largest-remainder pro-rata. Interest splits
     * largest-remainder pro-rata. See {@see InvestorDistributionService}.
     */
    public function processRepayment(int $loanId, int $amortizationScheduleId): void
    {
        // Collect notification data OUTSIDE the transaction.
        // Notifications are side effects — if email fails, the financial
        // operation must NOT roll back. Money first, emails second.
        $notificationQueue = [];

        DB::transaction(function () use ($loanId, $amortizationScheduleId, &$notificationQueue) {
            // Lock loan first — prevents new investments during repayment processing
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // Validate loan status — repayments only for active or late loans
            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_LATE])) {
                throw new InvalidArgumentException('Repayment can only be processed for active or late loans.');
            }

            // Offer-based loans pay their investors from per-investment
            // `investment_schedules` (PayoutAccrualService). Posting a
            // borrower-side amortization row on top of that would credit the
            // same investors a SECOND time (audit 2026-09-01, PAY-25). This
            // engine serves legacy (no-offer) loans only.
            if ($loan->usesOffers()) {
                throw new InvalidArgumentException(
                    "Loan #{$loanId} is offer-based: investors are paid from their own payout schedules. "
                    .'Use «Пусни плащане сега» instead of posting a legacy installment.'
                );
            }

            // Required + locked installment. Amounts come from the row, NOT the
            // caller. The 'paid' guard is unconditional now — no call path can
            // double-distribute the same installment.
            $schedule = AmortizationSchedule::where('id', $amortizationScheduleId)
                ->where('loan_id', $loanId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($schedule->status === 'paid') {
                throw new InvalidArgumentException('This amortization schedule entry has already been paid.');
            }

            $principalAmount = (string) $schedule->principal;
            $interestAmount = (string) $schedule->interest;

            if (bccomp(bcadd($principalAmount, $interestAmount, 2), '0', 2) <= 0) {
                throw new InvalidArgumentException('Installment has nothing to distribute (zero principal and interest).');
            }

            // Lock all investments (prevents concurrent funding mid-repayment).
            $investments = Investment::where('loan_id', $loanId)->lockForUpdate()->get();
            if ($investments->isEmpty()) {
                throw new InvalidArgumentException('No investors found for this loan.');
            }

            if (bccomp((string) $loan->funded_amount, '0', 2) <= 0) {
                throw new InvalidArgumentException('Loan has no funded amount.');
            }

            // Per-user outstanding capital + invested weights (deterministic order).
            $outstanding = $this->distribution->outstandingPrincipalByUser($loan);
            $investedWeights = $outstanding
                ->mapWithKeys(fn (array $e) => [$e['user_id'] => $e['invested']])
                ->all();

            // Is this the final unpaid installment? If so, principal returns
            // each investor's EXACT outstanding → Σ per investor == invested,
            // invested → 0, no drift, no clamp. Σ(outstanding) == this row's
            // principal because every prior installment distributed exactly.
            $unpaidCount = AmortizationSchedule::where('loan_id', $loanId)
                ->whereIn('status', ['pending', 'late'])
                ->count();
            $isFinalInstallment = $unpaidCount <= 1;

            if ($isFinalInstallment) {
                $principalShares = $outstanding
                    ->mapWithKeys(fn (array $e) => [$e['user_id'] => $e['outstanding']])
                    ->all();
            } else {
                $principalShares = $this->distribution->largestRemainderSplit($principalAmount, $investedWeights);
            }

            $interestShares = $this->distribution->largestRemainderSplit($interestAmount, $investedWeights);

            Log::info('Processing repayment', [
                'loan_id' => $loanId,
                'schedule_id' => $amortizationScheduleId,
                'principal' => $principalAmount,
                'interest' => $interestAmount,
                'investors_count' => $outstanding->count(),
                'final_installment' => $isFinalInstallment,
            ]);

            foreach ($outstanding as $entry) {
                $userId = $entry['user_id'];
                $principalShare = $principalShares[$userId] ?? '0.00';
                $interestShare = $interestShares[$userId] ?? '0.00';

                if (bccomp($principalShare, '0', 2) <= 0 && bccomp($interestShare, '0', 2) <= 0) {
                    continue;
                }

                $reference = "loan:{$loanId}:user:{$userId}";

                if (bccomp($principalShare, '0', 2) > 0) {
                    $this->walletService->repayPrincipal(
                        $userId,
                        $principalShare,
                        "Principal repayment for loan #{$loanId}",
                        $reference
                    );
                }

                if (bccomp($interestShare, '0', 2) > 0) {
                    $this->walletService->repayInterest(
                        $userId,
                        $interestShare,
                        "Interest repayment for loan #{$loanId}",
                        $reference
                    );
                }

                $notificationQueue[] = [
                    'user' => $entry['user'],
                    'loan_id' => $loanId,
                    'principal' => $principalShare,
                    'interest' => $interestShare,
                ];

                Log::info('Repayment distributed', [
                    'loan_id' => $loanId,
                    'investor_id' => $userId,
                    'principal_share' => $principalShare,
                    'interest_share' => $interestShare,
                ]);
            }

            $schedule->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        });

        // Send notifications AFTER transaction committed successfully.
        foreach ($notificationQueue as $item) {
            try {
                $item['user']->notify(new RepaymentReceivedNotification(
                    $item['loan_id'],
                    $item['principal'],
                    $item['interest']
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send repayment notification', [
                    'user_id' => $item['user']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
