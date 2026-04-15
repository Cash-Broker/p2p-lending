<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Notifications\WithdrawalApprovedNotification;
use App\Notifications\WithdrawalRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Create a withdrawal request and RESERVE the amount.
     *
     * Moves funds from available → reserved so the user can't
     * double-spend by creating multiple withdrawal requests or
     * investing the same money while a withdrawal is pending.
     */
    public function createRequest(int $userId, string $amount, string $iban): WithdrawalRequest
    {
        return DB::transaction(function () use ($userId, $amount, $iban) {
            // Reserve the amount — moves available → reserved
            // WalletService handles locking and validation internally
            try {
                $this->walletService->reserve($userId, $amount);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'amount' => [$e->getMessage()],
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

            // Debit from reserved (not available — amount was reserved at creation)
            $this->walletService->debitReserved(
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

            // Release reservation — moves reserved → available
            $this->walletService->releaseReservation(
                $withdrawal->user_id,
                $withdrawal->amount
            );

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
