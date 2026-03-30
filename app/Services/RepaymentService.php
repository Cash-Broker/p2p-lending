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

/**
 * Processes loan repayments and distributes them proportionally to investors.
 *
 * This is the most complex financial operation in the platform.
 * When a borrower makes a monthly payment, it consists of:
 *   - Principal: return of the borrowed amount
 *   - Interest: the profit the investor earns
 *
 * Distribution is proportional: if Investor A funded 30% of the loan,
 * they receive 30% of both principal and interest.
 *
 * Wallet balance changes per investor:
 *   - invested -= principal_share (money is no longer "at work")
 *   - available += principal_share + interest_share (money returns to wallet)
 *   - earned += interest_share (lifetime earnings tracker)
 *
 * All calculations use bcmath (string-based arbitrary precision) — never float.
 * The entire operation is wrapped in a DB transaction with row-level locks
 * on each investor's wallet to prevent concurrent balance corruption.
 */
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

        DB::transaction(function () use ($loanId, $principalAmount, $interestAmount, $amortizationScheduleId) {
            $loan = Loan::where('id', $loanId)->lockForUpdate()->firstOrFail();

            $investments = Investment::where('loan_id', $loanId)->get();

            if ($investments->isEmpty()) {
                Log::warning('Repayment attempted on loan with no investors', ['loan_id' => $loanId]);
                throw new InvalidArgumentException('No investors found for this loan.');
            }

            // Total funded amount — used as denominator for proportional split
            $totalFunded = $loan->funded_amount;

            if (bccomp($totalFunded, '0', 2) <= 0) {
                throw new InvalidArgumentException('Loan has no funded amount.');
            }

            Log::info('Processing repayment', [
                'loan_id' => $loanId,
                'principal' => $principalAmount,
                'interest' => $interestAmount,
                'investors_count' => $investments->count(),
                'total_funded' => $totalFunded,
            ]);

            foreach ($investments as $investment) {
                // Proportional share: investor_amount / total_funded
                // Using 10 decimal places for the ratio to minimize rounding errors
                $share = bcdiv($investment->amount, $totalFunded, 10);

                $principalShare = bcmul($principalAmount, $share, 2);
                $interestShare = bcmul($interestAmount, $share, 2);

                // Skip if both shares round to zero
                if (bccomp($principalShare, '0', 2) <= 0 && bccomp($interestShare, '0', 2) <= 0) {
                    continue;
                }

                // Lock this investor's wallet
                $wallet = Wallet::where('user_id', $investment->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Update wallet balances:
                // invested decreases (principal returns), available increases, earned tracks interest
                $wallet->forceFill([
                    'invested' => bcsub($wallet->invested, $principalShare, 2),
                    'available' => bcadd($wallet->available, bcadd($principalShare, $interestShare, 2), 2),
                    'earned' => bcadd($wallet->earned, $interestShare, 2),
                ])->save();

                // Create immutable transaction records
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

                Log::info('Repayment distributed to investor', [
                    'loan_id' => $loanId,
                    'investor_id' => $investment->user_id,
                    'share' => $share,
                    'principal_share' => $principalShare,
                    'interest_share' => $interestShare,
                ]);

                // Notify investor
                $investment->user->notify(new RepaymentReceivedNotification(
                    $loanId,
                    $principalShare,
                    $interestShare
                ));
            }

            // Update amortization schedule if provided
            if ($amortizationScheduleId) {
                AmortizationSchedule::where('id', $amortizationScheduleId)
                    ->where('loan_id', $loanId)
                    ->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                    ]);
            }
        });
    }
}
