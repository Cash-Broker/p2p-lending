<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Notifications\RepaymentReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RepaymentService
{
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
            // Lock all investments — prevents modification during distribution
            $investments = Investment::where('loan_id', $loanId)->lockForUpdate()->get();

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

            foreach ($investments as $investment) {
                $share = bcdiv($investment->amount, $totalFunded, 10);
                $principalShare = bcmul($principalAmount, $share, 2);
                $interestShare = bcmul($interestAmount, $share, 2);

                if (bccomp($principalShare, '0', 2) <= 0 && bccomp($interestShare, '0', 2) <= 0) {
                    continue;
                }

                $wallet = Wallet::where('user_id', $investment->user_id)->lockForUpdate()->firstOrFail();

                $wallet->forceFill([
                    'invested' => bcsub($wallet->invested, $principalShare, 2),
                    'available' => bcadd($wallet->available, bcadd($principalShare, $interestShare, 2), 2),
                    'earned' => bcadd($wallet->earned, $interestShare, 2),
                ])->save();

                if (bccomp($principalShare, '0', 2) > 0) {
                    Transaction::create([
                        'user_id' => $investment->user_id,
                        'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
                        'amount' => $principalShare,
                        'description' => "Principal repayment for loan #{$loanId}",
                        'reference' => "loan:{$loanId}:investment:{$investment->id}",
                        'ip_address' => request()?->ip(),
                        'user_agent' => request()?->userAgent(),
                    ]);
                }

                if (bccomp($interestShare, '0', 2) > 0) {
                    Transaction::create([
                        'user_id' => $investment->user_id,
                        'type' => Transaction::TYPE_REPAYMENT_INTEREST,
                        'amount' => $interestShare,
                        'description' => "Interest repayment for loan #{$loanId}",
                        'reference' => "loan:{$loanId}:investment:{$investment->id}",
                        'ip_address' => request()?->ip(),
                        'user_agent' => request()?->userAgent(),
                    ]);
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
        // If any email fails, the financial data is already safe in the DB.
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
