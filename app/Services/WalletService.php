<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Central service for ALL wallet balance changes.
 *
 * Every cent moving in or out of a wallet MUST go through this service.
 * Direct wallet balance manipulation outside this class is forbidden —
 * it would bypass locking, validation, and audit trail.
 *
 * Every operation:
 * 1. Acquires a row-level lock on the wallet (prevents concurrent modification)
 * 2. Validates the operation (e.g., debit can't exceed available balance)
 * 3. Updates the wallet balance using forceFill (bypasses mass-assignment protection)
 * 4. Creates an immutable transaction record with IP tracking
 */
class WalletService
{
    /**
     * Credit (increase) the available balance.
     * Used for: deposits, repayment principal, repayment interest.
     */
    public function credit(int $userId, string $amount, string $type, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $wallet->forceFill([
                'available' => bcadd($wallet->available, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Debit (decrease) the available balance.
     * Used for: withdrawals, fees.
     */
    public function debit(int $userId, string $amount, string $type, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->available, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient available balance.');
            }

            $wallet->forceFill([
                'available' => bcsub($wallet->available, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Reserve funds for a pending withdrawal.
     * Moves amount from available → reserved. No transaction record —
     * a reservation is a hold, not a ledger event.
     */
    public function reserve(int $userId, string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Reserve amount must be positive.');
        }

        DB::transaction(function () use ($userId, $amount) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->available, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient available balance.');
            }

            $wallet->forceFill([
                'available' => bcsub($wallet->available, $amount, 2),
                'reserved' => bcadd($wallet->reserved, $amount, 2),
            ])->save();
        });
    }

    /**
     * Release a reservation back to available balance.
     * Used when a withdrawal request is rejected.
     */
    public function releaseReservation(int $userId, string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Release amount must be positive.');
        }

        DB::transaction(function () use ($userId, $amount) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->reserved, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient reserved balance.');
            }

            $wallet->forceFill([
                'reserved' => bcsub($wallet->reserved, $amount, 2),
                'available' => bcadd($wallet->available, $amount, 2),
            ])->save();
        });
    }

    /**
     * Debit from reserved balance (withdrawal approved and processed).
     * Creates a transaction record since this is an actual money movement.
     */
    public function debitReserved(int $userId, string $amount, string $type, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->reserved, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient reserved balance.');
            }

            $wallet->forceFill([
                'reserved' => bcsub($wallet->reserved, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Process an investment: available → invested.
     * Creates a transaction record.
     */
    public function invest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Investment amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->available, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient available balance.');
            }

            $wallet->forceFill([
                'available' => bcsub($wallet->available, $amount, 2),
                'invested' => bcadd($wallet->invested, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => Transaction::TYPE_INVESTMENT,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Process repayment principal: invested → available.
     * Writes Transaction::TYPE_REPAYMENT_PRINCIPAL.
     */
    public function repayPrincipal(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableFromInvested(
            $userId, $amount, Transaction::TYPE_REPAYMENT_PRINCIPAL, $description, $reference,
        );
    }

    /**
     * Process repayment interest: → available + earned.
     * Writes Transaction::TYPE_REPAYMENT_INTEREST.
     */
    public function repayInterest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableAndEarned(
            $userId, $amount, Transaction::TYPE_REPAYMENT_INTEREST, $description, $reference,
        );
    }

    /**
     * F2 — originator buyback principal: invested → available.
     * Identical bucket math to repayPrincipal; separate type so
     * reconciliation + per-investor reporting can distinguish borrower
     * repayments from originator-honoured buybacks.
     */
    public function buybackPrincipal(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableFromInvested(
            $userId, $amount, Transaction::TYPE_BUYBACK_PRINCIPAL, $description, $reference,
        );
    }

    /**
     * F2 — originator buyback interest: → available + earned.
     * Identical bucket math to repayInterest; separate type per F2 Q13
     * (investor's `total_earned` view aggregates both repayment and
     * buyback interest — one income stream from the investor's POV).
     */
    public function buybackInterest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableAndEarned(
            $userId, $amount, Transaction::TYPE_BUYBACK_INTEREST, $description, $reference,
        );
    }

    /**
     * F3 — borrower early-repayment principal: invested → available.
     * Identical bucket math to repayPrincipal; separate type so
     * reconciliation and per-investor reporting can distinguish
     * scheduled repayments (monthly cadence) from full-close payoffs
     * (borrower closed the loan early).
     */
    public function earlyRepayPrincipal(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableFromInvested(
            $userId, $amount, Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL, $description, $reference,
        );
    }

    /**
     * F3 — borrower early-repayment interest: → available + earned.
     * Same bucket math as repayInterest; investor's earned-view aggregate
     * includes both scheduled and early-repayment interest (both are
     * borrower-paid income to the investor).
     */
    public function earlyRepayInterest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        return $this->creditAvailableAndEarned(
            $userId, $amount, Transaction::TYPE_EARLY_REPAYMENT_INTEREST, $description, $reference,
        );
    }

    /**
     * Accrue interest into the LOCKED `accrued` bucket — profit recognised on
     * schedule but not yet spendable. Grows "текущо салдо" (= invested +
     * accrued) without touching `available`. Because accrual happens on
     * schedule regardless of whether the borrower has actually paid, the
     * accrued bucket also marks the platform's outstanding exposure until the
     * profit is released.
     */
    public function accrueInterest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Accrual amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $wallet->forceFill([
                'accrued' => bcadd($wallet->accrued, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => Transaction::TYPE_INTEREST_ACCRUED,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Release locked profit from `accrued` into spendable `available` (and the
     * cumulative `earned` counter). Used when an offer's plan unlocks profit —
     * e.g. a capitalized offer at maturity. Cannot release more than is accrued.
     */
    public function releaseAccrued(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Release amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->accrued, $amount, 2) < 0) {
                throw new InvalidArgumentException('Insufficient accrued balance to release.');
            }

            $wallet->forceFill([
                'accrued' => bcsub($wallet->accrued, $amount, 2),
                'available' => bcadd($wallet->available, $amount, 2),
                'earned' => bcadd($wallet->earned, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => Transaction::TYPE_INTEREST_RELEASED,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Shared helper: move funds from `invested` bucket to `available`.
     * Used by repayPrincipal, buybackPrincipal AND earlyRepayPrincipal —
     * same wallet-bucket arithmetic, different transaction type + description.
     *
     * **Invested-underflow is now a HARD STOP (Phase 2 audit follow-up).**
     * Returning more principal than an investor holds in `invested` means we
     * would either go negative (DB CHECK violation) or — as the old clamp did
     * — silently MANUFACTURE spendable balance by zeroing `invested` while
     * still crediting the full amount to `available`. That clamp was the audit's
     * confirmed money-creation path. It is removed: an underflow now throws
     * and rolls the whole operation back (loud, safe), after an ERROR log so
     * ops can investigate the reconciliation_id. With the exact per-investor
     * distribution in RepaymentService (each investor's outstanding is returned
     * on the final installment; non-final installments under-return via floor),
     * a correct loan can never reach this branch — if it fires, the
     * distribution is genuinely wrong and must NOT be papered over.
     */
    private function creditAvailableFromInvested(
        int $userId,
        string $amount,
        string $type,
        string $description,
        ?string $reference,
    ): Transaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $newInvested = bcsub((string) $wallet->invested, $amount, 2);
            if (bccomp($newInvested, '0', 2) < 0) {
                $reconciliationId = (string) Str::uuid();
                $loanId = $this->parseLoanIdFromReference($reference);

                Log::error(
                    'WalletService: principal return exceeds invested balance — rejected (no money manufactured)',
                    [
                        'reconciliation_id' => $reconciliationId,
                        'user_id' => $userId,
                        'loan_id' => $loanId,
                        'type' => $type,
                        'requested_amount' => $amount,
                        'wallet_invested_before' => (string) $wallet->invested,
                        'shortfall' => $newInvested, // negative magnitude
                        'reference' => $reference,
                    ],
                );

                throw new InvestedUnderflowException(
                    "Refusing to return {$amount} of principal to user #{$userId}: "
                    ."only {$wallet->invested} invested remains (reconciliation_id={$reconciliationId}). "
                    .'This indicates a distribution bug — operation rolled back rather than manufacturing balance.'
                );
            }

            $wallet->forceFill([
                'invested' => $newInvested,
                'available' => bcadd($wallet->available, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Shared helper: credit `available` AND `earned` buckets.
     * Used by repayInterest AND buybackInterest — investor earnings view
     * treats both as income (per F2 Q13).
     */
    private function creditAvailableAndEarned(
        int $userId,
        string $amount,
        string $type,
        string $description,
        ?string $reference,
    ): Transaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $wallet->forceFill([
                'available' => bcadd($wallet->available, $amount, 2),
                'earned' => bcadd($wallet->earned, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Best-effort loan_id extraction from a transaction reference string.
     * Current convention is `loan:ID:...` (RepaymentService,
     * BuybackExecutionService, EarlyRepaymentExecutionService). Returns
     * null for references that don't carry a loan id
     * (e.g. withdrawal/fee references).
     */
    private function parseLoanIdFromReference(?string $reference): ?int
    {
        if (! $reference) {
            return null;
        }
        if (preg_match('/^loan:(\d+):/', $reference, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
