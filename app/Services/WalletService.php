<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
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
     * Shared helper: move funds from `invested` bucket to `available`.
     * Used by repayPrincipal, buybackPrincipal AND earlyRepayPrincipal —
     * same wallet-bucket arithmetic, different transaction type + description.
     *
     * **Last-investor-remainder drift absorption (Phase 2 audit finding).**
     * The pro-rata last-investor-remainder pattern in RepaymentService /
     * BuybackCalculationService / EarlyRepaymentCalculationService guarantees
     * `Σ distributions == installment_total` per installment, but NOT
     * `Σ per-investor distributions across all installments == investor's
     * invested`. Non-last investors get floor() shares that cumulatively
     * under-return; the last investor cumulatively over-returns by the
     * same amount. Platform money is conserved across investors (no
     * net gain/loss), but the last investor's wallet can try to
     * subtract 1–2 stotinki MORE principal than they originally invested.
     *
     * Before Phase 2 fix, that underflow hit the `chk_wallets_invested
     * _non_negative` DB CHECK and rolled back the entire repayment —
     * leaving production operators unable to close certain loans. The
     * clamp below detects the underflow case and zeroes `invested`
     * instead of going negative. The full requested amount still
     * credits to `available` (money movement is valid in aggregate).
     * We log at WARNING level so unusual drift is visible in ops.
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

            $rawInvested = bcsub((string) $wallet->invested, $amount, 2);
            if (bccomp($rawInvested, '0', 2) < 0) {
                // Clamp. Log the drift so ops can see cumulative rounding
                // artifacts in the wild. Drift is almost always ≤ 0.02.
                \Illuminate\Support\Facades\Log::warning(
                    'WalletService: invested bucket drift clamped at zero',
                    [
                        'user_id'                => $userId,
                        'type'                   => $type,
                        'requested_amount'       => $amount,
                        'wallet_invested_before' => (string) $wallet->invested,
                        'drift_absorbed'         => $rawInvested, // negative magnitude shows drift
                        'reference'              => $reference,
                    ],
                );
                $newInvested = '0.00';
            } else {
                $newInvested = $rawInvested;
            }

            $wallet->forceFill([
                'invested'  => $newInvested,
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
}
