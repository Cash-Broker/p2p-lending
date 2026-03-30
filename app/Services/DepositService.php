<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class DepositService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Create a pending deposit request.
     * The investor makes a bank transfer with the reference_code in the payment description.
     * Admin manually matches the transfer and approves it.
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
     * Admin approves a deposit — credits the investor's wallet.
     */
    public function approve(int $depositRequestId, int $adminId): DepositRequest
    {
        return DB::transaction(function () use ($depositRequestId, $adminId) {
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
    }

    /**
     * Admin rejects a deposit — no wallet change, just status update.
     */
    public function reject(int $depositRequestId, int $adminId, ?string $note = null): DepositRequest
    {
        $deposit = DepositRequest::where('id', $depositRequestId)
            ->where('status', 'pending')
            ->firstOrFail();

        $deposit->update([
            'status' => 'rejected',
            'admin_note' => $note ?? "Rejected by admin #{$adminId}",
        ]);

        return $deposit;
    }
}
