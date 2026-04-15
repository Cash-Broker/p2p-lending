<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Notifications\RepaymentReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RepaymentService
{
    public function __construct(private WalletService $walletService) {}

    public function processRepayment(
        int $loanId,
        string $principalAmount,
        string $interestAmount,
        ?int $amortizationScheduleId = null
    ): void {
        if (bccomp($principalAmount, '0', 2) < 0 || bccomp($interestAmount, '0', 2) < 0) {
            throw new InvalidArgumentException('Repayment amounts cannot be negative.');
        }

        $totalRepayment = bcadd($principalAmount, $interestAmount, 2);
        if (bccomp($totalRepayment, '0', 2) <= 0) {
            throw new InvalidArgumentException('Total repayment must be greater than zero.');
        }

        // Collect notification data OUTSIDE the transaction.
        // Notifications are side effects — if email fails, the financial
        // operation must NOT roll back. Money first, emails second.
        $notificationQueue = [];

        DB::transaction(function () use ($loanId, $principalAmount, $interestAmount, $amortizationScheduleId, &$notificationQueue) {
            // Lock loan first — prevents new investments during repayment processing
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            // Validate loan status — repayments only for active or late loans
            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_LATE])) {
                throw new InvalidArgumentException('Repayment can only be processed for active or late loans.');
            }

            // Duplicate guard — if schedule provided, verify it's still pending
            if ($amortizationScheduleId) {
                $schedule = AmortizationSchedule::where('id', $amortizationScheduleId)
                    ->where('loan_id', $loanId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($schedule->status === 'paid') {
                    throw new InvalidArgumentException('This amortization schedule entry has already been paid.');
                }
            }

            // Lock all investments and eager load users (fixes N+1)
            $investments = Investment::where('loan_id', $loanId)
                ->with('user')
                ->lockForUpdate()
                ->get();

            if ($investments->isEmpty()) {
                throw new InvalidArgumentException('No investors found for this loan.');
            }

            $totalFunded = $loan->funded_amount;
            if (bccomp($totalFunded, '0', 2) <= 0) {
                throw new InvalidArgumentException('Loan has no funded amount.');
            }

            Log::info('Processing repayment', [
                'loan_id' => $loanId,
                'principal' => $principalAmount,
                'interest' => $interestAmount,
                'investors_count' => $investments->count(),
            ]);

            // Distribute repayment — last investor gets remainder to prevent penny loss
            $distributedPrincipal = '0.00';
            $distributedInterest = '0.00';
            $lastIndex = $investments->count() - 1;

            foreach ($investments as $index => $investment) {
                if ($index === $lastIndex) {
                    // Last investor gets the remainder — guarantees sum == total
                    $principalShare = bcsub($principalAmount, $distributedPrincipal, 2);
                    $interestShare = bcsub($interestAmount, $distributedInterest, 2);
                } else {
                    $share = bcdiv($investment->amount, $totalFunded, 10);
                    $principalShare = bcmul($principalAmount, $share, 2);
                    $interestShare = bcmul($interestAmount, $share, 2);
                    $distributedPrincipal = bcadd($distributedPrincipal, $principalShare, 2);
                    $distributedInterest = bcadd($distributedInterest, $interestShare, 2);
                }

                if (bccomp($principalShare, '0', 2) <= 0 && bccomp($interestShare, '0', 2) <= 0) {
                    continue;
                }

                $reference = "loan:{$loanId}:investment:{$investment->id}";

                // Use WalletService for all balance changes
                if (bccomp($principalShare, '0', 2) > 0) {
                    $this->walletService->repayPrincipal(
                        $investment->user_id,
                        $principalShare,
                        "Principal repayment for loan #{$loanId}",
                        $reference
                    );
                }

                if (bccomp($interestShare, '0', 2) > 0) {
                    $this->walletService->repayInterest(
                        $investment->user_id,
                        $interestShare,
                        "Interest repayment for loan #{$loanId}",
                        $reference
                    );
                }

                // Queue notification for AFTER transaction commits
                $notificationQueue[] = [
                    'user' => $investment->user,
                    'loan_id' => $loanId,
                    'principal' => $principalShare,
                    'interest' => $interestShare,
                ];

                Log::info('Repayment distributed', [
                    'loan_id' => $loanId,
                    'investor_id' => $investment->user_id,
                    'principal_share' => $principalShare,
                    'interest_share' => $interestShare,
                ]);
            }

            if ($amortizationScheduleId) {
                AmortizationSchedule::where('id', $amortizationScheduleId)
                    ->where('loan_id', $loanId)
                    ->update(['status' => 'paid', 'paid_at' => now()]);
            }
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
