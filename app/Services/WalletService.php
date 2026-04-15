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
     */
    public function repayPrincipal(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Repayment amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $wallet->forceFill([
                'invested' => bcsub($wallet->invested, $amount, 2),
                'available' => bcadd($wallet->available, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => Transaction::TYPE_REPAYMENT_PRINCIPAL,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    /**
     * Process repayment interest: → available + earned.
     */
    public function repayInterest(int $userId, string $amount, string $description, ?string $reference = null): Transaction
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Repayment amount must be positive.');
        }

        return DB::transaction(function () use ($userId, $amount, $description, $reference) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            $wallet->forceFill([
                'available' => bcadd($wallet->available, $amount, 2),
                'earned' => bcadd($wallet->earned, $amount, 2),
            ])->save();

            return Transaction::create([
                'user_id' => $userId,
                'type' => Transaction::TYPE_REPAYMENT_INTEREST,
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
