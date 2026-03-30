<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentService
{
    /**
     * Process an investment in a loan.
     *
     * This is the most critical financial operation in a P2P lending platform.
     * Every step must be atomic — if any step fails, everything rolls back.
     *
     * The flow:
     * 1. Lock wallet row (prevents concurrent balance manipulation)
     * 2. Lock loan row (prevents overfunding from concurrent investments)
     * 3. Validate business rules (balance, loan status, amounts)
     * 4. Debit wallet: available -= amount, invested += amount
     * 5. Credit loan: funded_amount += amount
     * 6. Create Investment record
     * 7. Create Transaction record (immutable ledger entry)
     * 8. If loan fully funded → update status to 'funded'
     *
     * lockForUpdate() uses SELECT ... FOR UPDATE which acquires a row-level
     * exclusive lock in MySQL. Any other transaction trying to read the same
     * row with FOR UPDATE will WAIT until this transaction commits or rolls back.
     */
    public function invest(User $user, Loan $loan, string $amount): Investment
    {
        return DB::transaction(function () use ($user, $loan, $amount) {
            // Step 1: Lock wallet — prevents double-spend
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            // Step 2: Lock loan — prevents overfunding
            $loan = Loan::where('id', $loan->id)->lockForUpdate()->firstOrFail();

            // Step 3: Business rule validations (AFTER locks, not before)
            $this->validateInvestment($wallet, $loan, $amount);

            // Step 4: Debit wallet
            $wallet->forceFill([
                'available' => bcsub($wallet->available, $amount, 2),
                'invested' => bcadd($wallet->invested, $amount, 2),
            ])->save();

            // Step 5: Credit loan
            $newFundedAmount = bcadd($loan->funded_amount, $amount, 2);
            $loan->forceFill(['funded_amount' => $newFundedAmount])->save();

            // Step 6: Create investment record
            $investment = Investment::create([
                'user_id' => $user->id,
                'loan_id' => $loan->id,
                'amount' => $amount,
                'invested_at' => now(),
            ]);

            // Step 7: Create immutable transaction record
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_INVESTMENT,
                'amount' => $amount,
                'description' => "Investment in loan #{$loan->id}",
                'reference' => "investment:{$investment->id}",
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            // Step 8: Auto-transition loan status
            if ($loan->isFullyFunded()) {
                $loan->forceFill(['status' => Loan::STATUS_FUNDED])->save();
            } elseif ($loan->status === Loan::STATUS_PUBLISHED) {
                // First investment transitions from published → funding
                $loan->forceFill(['status' => Loan::STATUS_FUNDING])->save();
            }

            return $investment;
        });
    }

    private function validateInvestment(Wallet $wallet, Loan $loan, string $amount): void
    {
        // Minimum investment amount
        if (bccomp($amount, '50.00', 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Minimum investment is 50.00 €.'],
            ]);
        }

        // Loan must be in a fundable status
        if (! in_array($loan->status, Loan::FUNDABLE_STATUSES)) {
            throw ValidationException::withMessages([
                'loan' => ['This loan is not available for investment.'],
            ]);
        }

        // Check available balance
        if (bccomp($wallet->available, $amount, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Insufficient available balance.'],
            ]);
        }

        // Check overfunding — investment must not exceed remaining amount
        $remaining = bcsub($loan->amount, $loan->funded_amount, 2);
        if (bccomp($amount, $remaining, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Maximum available for this loan is {$remaining} €."],
            ]);
        }
    }
}
