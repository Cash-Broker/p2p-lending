<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Notifications\DepositApprovedNotification;
use App\Notifications\DepositRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositService
{
    public function __construct(
        private WalletService $walletService,
        private \App\Services\TelegramService $telegram,
    ) {}

    /**
     * Threshold above which deposits trigger a Telegram HIGH alert.
     * Higher than the withdrawal threshold because deposits skew larger
     * (one-time funding, not periodic withdrawals).
     */
    private const TELEGRAM_BIG_DEPOSIT_EUR = '5000.00';

    /**
     * Create a DepositRequest with a known amount (legacy entry point).
     *
     * Used by audit/integration tests to seed deposits with deterministic
     * amounts. Production-side code creates DepositRequests via
     * getOrCreateActiveCode (amount=null at issuance) and fills the amount
     * in when admin credits via Filament.
     */
    public function createRequest(int $userId, string $amount): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $userId,
            'amount' => $amount,
            'status' => 'pending',
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /**
     * Return the user's active deposit code, creating one if none exists.
     *
     * Idempotency contract: repeated calls return the SAME code while it's
     * still active (status=pending AND not expired). When the code expires
     * or gets approved/rejected, the next call mints a fresh DEP-XXXXXXXX.
     *
     * Amount is null at issuance time — we don't know how much the user
     * will wire. Admin fills it in when crediting via the Filament form.
     */
    public function getOrCreateActiveCode(int $userId): DepositRequest
    {
        $existing = DepositRequest::where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($q) {
                // NULL expires_at = legacy pre-refactor row → treat as non-expiring
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        return DepositRequest::create([
            'user_id' => $userId,
            'amount' => null,
            'status' => 'pending',
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    public function approve(int $depositRequestId, int $adminId): DepositRequest
    {
        $deposit = DB::transaction(function () use ($depositRequestId, $adminId) {
            $deposit = DepositRequest::where('id', $depositRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            // Defense-in-depth: amount must have been set before approve.
            // Filament form requires it; this guard catches programmatic
            // misuse (e.g., service called without setting amount first).
            if ($deposit->amount === null || bccomp((string) $deposit->amount, '0', 2) <= 0) {
                throw new \DomainException("Cannot approve deposit #{$deposit->id}: amount not set.");
            }

            // Bank-reference uniqueness — closes audit H5. The DB UNIQUE
            // constraint already prevents duplicate inserts, but we check
            // here too so the error is a clean DomainException instead of
            // a UniqueConstraintViolationException leaking SQL details.
            // Approved deposits with the same reference are the only collision
            // we care about; pending ones may hold the same value as a
            // shared placeholder until admin commits one of them.
            if (! empty($deposit->bank_reference)) {
                $duplicate = DepositRequest::where('bank_reference', $deposit->bank_reference)
                    ->where('status', 'approved')
                    ->where('id', '!=', $deposit->id)
                    ->exists();
                if ($duplicate) {
                    throw new \DomainException(
                        "Bank reference '{$deposit->bank_reference}' has already been credited to another deposit."
                    );
                }
            }

            $this->walletService->credit(
                $deposit->user_id,
                $deposit->amount,
                Transaction::TYPE_DEPOSIT,
                "Deposit approved (ref: {$deposit->reference_code})",
                "deposit_request:{$deposit->id}"
            );

            $deposit->update([
                'status' => 'approved',
                'confirmed_at' => now(),
                'admin_note' => "Approved by admin #{$adminId}",
            ]);

            return $deposit;
        });

        // Notifications AFTER transaction — email failure must not rollback money
        try {
            $deposit->user->notify(new DepositApprovedNotification($deposit->amount, $deposit->reference_code));
        } catch (\Throwable $e) {
            Log::warning('Failed to send deposit approved notification', ['deposit_id' => $deposit->id, 'error' => $e->getMessage()]);
        }

        // Telegram alert (HIGH tier) — only for big deposits.
        if (bccomp($deposit->amount, self::TELEGRAM_BIG_DEPOSIT_EUR, 2) >= 0) {
            try {
                $this->telegram->high(
                    'Голям депозит потвърден',
                    "Депозит #{$deposit->id} на {$deposit->amount} € кредитиран по сметка.",
                    [
                        'deposit_id' => $deposit->id,
                        'user_id'    => $deposit->user_id,
                        'amount_eur' => $deposit->amount,
                        'reference'  => $deposit->reference_code,
                        'admin_id'   => $adminId,
                    ],
                );
            } catch (\Throwable $ignored) {
                // Logged inside TelegramService.
            }
        }

        return $deposit;
    }

    public function reject(int $depositRequestId, int $adminId, ?string $note = null): DepositRequest
    {
        $deposit = DB::transaction(function () use ($depositRequestId, $adminId, $note) {
            $deposit = DepositRequest::where('id', $depositRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $deposit->update([
                'status' => 'rejected',
                'admin_note' => $note ?? "Rejected by admin #{$adminId}",
            ]);

            return $deposit;
        });

        try {
            $deposit->user->notify(new DepositRejectedNotification($deposit->amount, $note));
        } catch (\Throwable $e) {
            Log::warning('Failed to send deposit rejected notification', ['deposit_id' => $deposit->id, 'error' => $e->getMessage()]);
        }

        return $deposit;
    }
}
