<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\DepositApprovedNotification;
use App\Notifications\DepositRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositService
{
    public function __construct(
        private WalletService $walletService,
        private TelegramService $telegram,
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
     * Idempotency contract: repeated calls return the SAME code until the
     * code is consumed by an admin decision (approve/reject). A pending
     * code never expires or rotates on its own — the user may already
     * have wired money against it, so retiring it while unused would
     * strand a real bank transfer (client decision 2026-07-17; reverses
     * the 30-day rotation from audit finding M1).
     *
     * Amount is null at issuance time — we don't know how much the user
     * will wire. Admin fills it in when crediting via the Filament form.
     */
    public function getOrCreateActiveCode(int $userId): DepositRequest
    {
        // attempts=3: AccountDeletionService locks deposit_requests (gap)
        // before the users row — the inverse order of this transaction — so
        // a user racing their own deletion against a first-time code fetch
        // can deadlock (InnoDB 1213). The closure is idempotent, retry is
        // safe and resolves the race in whichever order InnoDB picked.
        return DB::transaction(function () use ($userId) {
            // Serialize per user. Without this lock two concurrent calls
            // (two tabs, phone + laptop) can both see "no active code" and
            // each mint one — the user then gets a different code per
            // request, mid-wire. A gap lock on deposit_requests would not
            // help (gap locks are compatible → deadlock on insert), so we
            // lock the user row instead.
            User::whereKey($userId)->lockForUpdate()->first();

            // latest('id'): deterministic even when duplicate pending rows
            // already exist (pre-fix races) — created_at has second
            // precision, so latest() alone could flip between requests.
            $existing = DepositRequest::where('user_id', $userId)
                ->where('status', 'pending')
                ->latest('id')
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
        }, 3);
    }

    /**
     * Approve a pending deposit and credit the user's wallet.
     *
     * $amount / $bankReference (the credit-by-code flow) are stamped INSIDE
     * the locked transaction — never pre-committed by the caller. That makes
     * stamp+credit atomic: two admins racing the same code cannot overwrite
     * each other's wire details (the loser blocks on the row lock, then
     * fails the status=pending recheck with ModelNotFoundException), and any
     * failure below rolls the stamp back too, so a real wire's UNIQUE
     * bank_reference is never burned onto a row that was not credited.
     */
    public function approve(int $depositRequestId, int $adminId, ?string $amount = null, ?string $bankReference = null): DepositRequest
    {
        $deposit = DB::transaction(function () use ($depositRequestId, $adminId, $amount, $bankReference) {
            $deposit = DepositRequest::where('id', $depositRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            if ($amount !== null) {
                $deposit->amount = $amount;
            }
            if ($bankReference !== null) {
                $deposit->bank_reference = $bankReference;
            }

            // Defense-in-depth: amount must have been set before approve.
            // Filament form requires it; this guard catches programmatic
            // misuse (e.g., service called without setting amount first).
            if ($deposit->amount === null || bccomp((string) $deposit->amount, '0', 2) <= 0) {
                throw new \DomainException("Cannot approve deposit #{$deposit->id}: amount not set.");
            }

            // GDPR-anonymized accounts have no wallet row (hard-deleted by
            // AccountDeletionService), but legacy pending codes of deleted
            // accounts may still resolve. Fail cleanly BEFORE any mutation
            // persists — otherwise WalletService::credit would throw
            // ModelNotFoundException deeper in.
            if (! Wallet::where('user_id', $deposit->user_id)->exists()) {
                throw new \DomainException("Cannot approve deposit #{$deposit->id}: the account has been closed (no wallet).");
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

            // update() saves ALL dirty attributes — the stamped amount and
            // bank_reference (if any) persist atomically with the approval.
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
                        'user_id' => $deposit->user_id,
                        'amount_eur' => $deposit->amount,
                        'reference' => $deposit->reference_code,
                        'admin_id' => $adminId,
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
