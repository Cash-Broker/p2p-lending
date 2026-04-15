<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Process an investment in a loan.
     *
     * The flow:
     * 1. Check idempotency — if this key was already used, return existing investment
     * 2. Lock loan row (prevents overfunding from concurrent investments)
     * 3. Validate business rules (loan status, amounts)
     * 4. Create Investment record
     * 5. Debit wallet via WalletService (available -= amount, invested += amount)
     * 6. Credit loan: funded_amount += amount
     * 7. If loan fully funded → transition to 'funded' (admin activates manually)
     */
    public function invest(User $user, Loan $loan, string $amount, ?string $idempotencyKey = null): Investment
    {
        try {
            return DB::transaction(function () use ($user, $loan, $amount, $idempotencyKey) {
                // Idempotency check INSIDE transaction — prevents race condition
                // where two concurrent requests both pass the check before either creates
                if ($idempotencyKey) {
                    $existing = Investment::where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                // Lock loan — prevents overfunding from concurrent investments
                $loan = Loan::where('id', $loan->id)->lockForUpdate()->firstOrFail();

                // Business rule validations (AFTER lock to prevent TOCTOU)
                $this->validateInvestment($user, $loan, $amount);

                // Create investment record
                $investment = Investment::create([
                    'user_id' => $user->id,
                    'loan_id' => $loan->id,
                    'amount' => $amount,
                    'invested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Debit wallet via WalletService — the single source of truth
                $this->walletService->invest(
                    $user->id,
                    $amount,
                    "Investment in loan #{$loan->id}",
                    "investment:{$investment->id}"
                );

                // Credit loan funded amount
                $newFundedAmount = bcadd($loan->funded_amount, $amount, 2);
                $loan->forceFill(['funded_amount' => $newFundedAmount])->save();

                // Auto-transition loan status
                if ($loan->isFullyFunded()) {
                    $loan->transitionTo(Loan::STATUS_FUNDED);
                } elseif ($loan->status === Loan::STATUS_PUBLISHED) {
                    $loan->transitionTo(Loan::STATUS_FUNDING);
                }

                return $investment;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent request with same idempotency key — return existing
            return Investment::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    private function validateInvestment(User $user, Loan $loan, string $amount): void
    {
        if (bccomp($amount, '50.00', 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Minimum investment is 50.00 €.'],
            ]);
        }

        if (! in_array($loan->status, Loan::FUNDABLE_STATUSES)) {
            throw ValidationException::withMessages([
                'loan' => ['This loan is not available for investment.'],
            ]);
        }

        // Check available balance (WalletService will also check, but fail fast here)
        $wallet = $user->wallet;
        if (bccomp($wallet->available, $amount, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Insufficient available balance.'],
            ]);
        }

        // Check overfunding
        $remaining = bcsub($loan->amount, $loan->funded_amount, 2);
        if (bccomp($amount, $remaining, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Maximum available for this loan is {$remaining} €."],
            ]);
        }
    }
}
