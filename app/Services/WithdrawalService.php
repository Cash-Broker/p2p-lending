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
    public function __construct(
        private WalletService $walletService,
        private FeeService $feeService,
    ) {}

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

            // Fee lookup happens INSIDE the transaction so a mid-approve
            // flag flip can't create a TOCTOU split between what the
            // admin previewed and what actually gets charged.
            $quote = $this->feeService->getQuote(
                FeeService::CATEGORY_WITHDRAWAL,
                $withdrawal->amount,
            );

            $ibanSuffix = substr($withdrawal->iban, -4);
            $withdrawalRef = "withdrawal_request:{$withdrawal->id}";

            if ($quote->applies) {
                // Fee-on path. Both debits come out of the RESERVED bucket
                // (the investor set aside the full gross amount at request
                // time). Net = what wires to the investor's bank; fee =
                // what stays with the platform (off-platform).
                // Both transactions live in this single DB::transaction,
                // so partial commits are impossible.
                $net = bcsub($withdrawal->amount, $quote->amount, 2);
                if (bccomp($net, '0', 2) <= 0) {
                    throw ValidationException::withMessages([
                        'fee' => ["Withdrawal amount ({$withdrawal->amount}) must exceed fee ({$quote->amount}). Either raise the withdrawal or reject it."],
                    ]);
                }

                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $net,
                    Transaction::TYPE_WITHDRAWAL,
                    "Withdrawal approved (IBAN: ****{$ibanSuffix}; net of {$quote->amount} € fee)",
                    $withdrawalRef,
                );

                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $quote->amount,
                    Transaction::TYPE_FEE,
                    "Такса при теглене #{$withdrawal->id} (IBAN: ****{$ibanSuffix})",
                    "{$withdrawalRef}:fee",
                );
            } else {
                // Fee-off path — byte-identical to pre-F4 behaviour. The
                // full withdrawal amount leaves reserved as one
                // TYPE_WITHDRAWAL transaction; no TYPE_FEE row.
                $this->walletService->debitReserved(
                    $withdrawal->user_id,
                    $withdrawal->amount,
                    Transaction::TYPE_WITHDRAWAL,
                    "Withdrawal approved (IBAN: ****{$ibanSuffix})",
                    $withdrawalRef,
                );
            }

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
