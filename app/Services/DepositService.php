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

    public function approve(int $depositRequestId, int $adminId): DepositRequest
    {
        $deposit = DB::transaction(function () use ($depositRequestId, $adminId) {
            $deposit = DepositRequest::where('id', $depositRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

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
