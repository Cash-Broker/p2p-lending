<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalApprovedNotification;
use App\Notifications\WithdrawalRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public function __construct(private WalletService $walletService) {}

    public function createRequest(int $userId, string $amount, string $iban): WithdrawalRequest
    {
        return DB::transaction(function () use ($userId, $amount, $iban) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (bccomp($wallet->available, $amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient available balance.'],
                ]);
            }

            return WithdrawalRequest::create([
                'user_id' => $userId,
                'amount' => $amount,
                'iban' => $iban,
                'status' => 'pending',
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }

    public function approve(int $withdrawalRequestId, int $adminId): WithdrawalRequest
    {
        $withdrawal = DB::transaction(function () use ($withdrawalRequestId, $adminId) {
            $withdrawal = WithdrawalRequest::where('id', $withdrawalRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $this->walletService->debit(
                $withdrawal->user_id,
                $withdrawal->amount,
                Transaction::TYPE_WITHDRAWAL,
                "Withdrawal approved (IBAN: ****" . substr($withdrawal->iban, -4) . ")",
                "withdrawal_request:{$withdrawal->id}"
            );

            $withdrawal->update([
                'status' => 'approved',
                'processed_at' => now(),
                'admin_note' => "Approved by admin #{$adminId}",
            ]);

            return $withdrawal;
        });

        try {
            $withdrawal->user->notify(new WithdrawalApprovedNotification($withdrawal->amount));
        } catch (\Throwable $e) {
            Log::warning('Failed to send withdrawal approved notification', ['withdrawal_id' => $withdrawal->id, 'error' => $e->getMessage()]);
        }

        return $withdrawal;
    }

    public function reject(int $withdrawalRequestId, int $adminId, ?string $note = null): WithdrawalRequest
    {
        $withdrawal = DB::transaction(function () use ($withdrawalRequestId, $adminId, $note) {
            $withdrawal = WithdrawalRequest::where('id', $withdrawalRequestId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $withdrawal->update([
                'status' => 'rejected',
                'admin_note' => $note ?? "Rejected by admin #{$adminId}",
            ]);

            return $withdrawal;
        });

        try {
            $withdrawal->user->notify(new WithdrawalRejectedNotification($withdrawal->amount, $note));
        } catch (\Throwable $e) {
            Log::warning('Failed to send withdrawal rejected notification', ['withdrawal_id' => $withdrawal->id, 'error' => $e->getMessage()]);
        }

        return $withdrawal;
    }
}
