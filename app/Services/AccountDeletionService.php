<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * GDPR-compliant account deletion (Right to Erasure, Article 17).
 *
 * In fintech, we can't simply DELETE the user record because:
 * 1. Active investments have money locked in loans — can't delete mid-loan
 * 2. Transaction records must be kept for 5 years (regulatory requirement)
 * 3. Audit logs reference the user — deleting would break the audit trail
 *
 * Solution: ANONYMIZATION instead of deletion.
 * - Replace all PII with non-identifying placeholders
 * - Keep financial records intact but de-linked from real identity
 * - Disable the account so it can't be used
 *
 * Pre-conditions for deletion:
 * - No active investments (invested balance must be 0)
 * - No pending deposits or withdrawals
 * - Available balance must be 0 (user must withdraw everything first)
 */
class AccountDeletionService
{
    public function deleteAccount(User $user, string $currentPassword): void
    {
        // Verify password — critical for preventing unauthorized deletion
        if (! \Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Грешна парола.'],
            ]);
        }

        $wallet = $user->wallet;

        // Cannot delete with active investments
        if ($wallet && bccomp($wallet->invested, '0', 2) > 0) {
            throw ValidationException::withMessages([
                'account' => ['Не може да изтриете акаунта си докато имате активни инвестиции.'],
            ]);
        }

        // Cannot delete with remaining balance
        if ($wallet && bccomp($wallet->available, '0', 2) > 0) {
            throw ValidationException::withMessages([
                'account' => ['Моля, изтеглете наличния си баланс преди да изтриете акаунта.'],
            ]);
        }

        // Cannot delete with pending requests
        $pendingDeposits = $user->depositRequests()->where('status', 'pending')->where('amount', '>', 0)->count();
        $pendingWithdrawals = $user->withdrawalRequests()->where('status', 'pending')->count();

        if ($pendingDeposits > 0 || $pendingWithdrawals > 0) {
            throw ValidationException::withMessages([
                'account' => ['Имате чакащи заявки за депозит или теглене. Изчакайте да бъдат обработени.'],
            ]);
        }

        DB::transaction(function () use ($user) {
            $userId = $user->id;

            Log::info('Account deletion requested', [
                'user_id' => $userId,
                'email' => $user->email,
                'ip_address' => request()?->ip(),
            ]);

            // Anonymize PII — replace with non-identifying placeholders
            $user->forceFill([
                'name' => "Изтрит потребител #{$userId}",
                'email' => "deleted_{$userId}@removed.p2pinvest.bg",
                'phone' => null,
                'password' => \Hash::make(\Str::random(64)), // Unguessable password
                'remember_token' => null,
                'email_verified_at' => null,
                'kyc_status' => 'pending',
                'kyc_document_path' => null,
            ])->save();

            // Delete saved IBANs (PII)
            $user->savedIbans()->delete();

            // Delete consent records (linked to identity)
            $user->consentRecords()->delete();

            // Clear notifications
            $user->notifications()->delete();

            // Delete wallet (balance is 0)
            $user->wallet()->delete();

            // Note: transactions, investments, audit_logs are KEPT
            // They reference user_id but the user is now "Изтрит потребител #X"
            // This satisfies both GDPR (data is anonymized) and
            // regulatory requirements (financial records preserved)

            Log::info('Account anonymized successfully', ['user_id' => $userId]);
        });
    }
}
