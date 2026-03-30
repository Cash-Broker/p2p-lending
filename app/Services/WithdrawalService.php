<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Create a pending withdrawal request.
     * Validates that the user has enough available balance BEFORE creating the request.
     */
    public function createRequest(int $userId, string $amount, string $iban): WithdrawalRequest
    {
        return DB::transaction(function () use ($userId, $amount, $iban) {
            // Lock wallet to prevent race condition where user submits multiple
            // withdrawal requests simultaneously, each checking the same balance
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

    /**
     * Admin approves a withdrawal — debits the investor's wallet.
     */
    public function approve(int $withdrawalRequestId, int $adminId): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalRequestId, $adminId) {
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
    }

    /**
     * Admin rejects a withdrawal — no wallet change.
     */
    public function reject(int $withdrawalRequestId, int $adminId, ?string $note = null): WithdrawalRequest
    {
        $withdrawal = WithdrawalRequest::where('id', $withdrawalRequestId)
            ->where('status', 'pending')
            ->firstOrFail();

        $withdrawal->update([
            'status' => 'rejected',
            'admin_note' => $note ?? "Rejected by admin #{$adminId}",
        ]);

        return $withdrawal;
    }
}
