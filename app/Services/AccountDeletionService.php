<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // Capture the KYC document paths before the transaction nulls them, so
        // we can erase the files from disk afterwards. We delete only AFTER the
        // anonymization commits — a mid-transaction failure (e.g. active
        // investments) must not destroy files for an account that still exists.
        $kycFiles = array_filter([
            $user->kyc_document_front_path,
            $user->kyc_document_back_path,
            $user->kyc_selfie_path,
        ]);

        DB::transaction(function () use ($user) {
            $userId = $user->id;

            // Lock wallet FIRST to prevent concurrent financial operations
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            // All balance checks inside transaction with lock — prevents race conditions
            if ($wallet && bccomp($wallet->invested, '0', 2) > 0) {
                throw ValidationException::withMessages([
                    'account' => ['Не може да изтриете акаунта си докато имате активни инвестиции.'],
                ]);
            }

            if ($wallet && bccomp($wallet->available, '0', 2) > 0) {
                throw ValidationException::withMessages([
                    'account' => ['Моля, изтеглете наличния си баланс преди да изтриете акаунта.'],
                ]);
            }

            if ($wallet && bccomp($wallet->reserved, '0', 2) > 0) {
                throw ValidationException::withMessages([
                    'account' => ['Имате резервирани средства за чакащо теглене. Изчакайте да бъде обработено.'],
                ]);
            }

            // Lock and check pending requests inside transaction
            $pendingDeposits = $user->depositRequests()->where('status', 'pending')->where('amount', '>', 0)->lockForUpdate()->count();
            $pendingWithdrawals = $user->withdrawalRequests()->where('status', 'pending')->lockForUpdate()->count();

            if ($pendingDeposits > 0 || $pendingWithdrawals > 0) {
                throw ValidationException::withMessages([
                    'account' => ['Имате чакащи заявки за депозит или теглене. Изчакайте да бъдат обработени.'],
                ]);
            }

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
                'password' => \Hash::make(\Str::random(64)),
                'remember_token' => null,
                'email_verified_at' => null,
                'kyc_status' => 'pending',
                'kyc_document_front_path' => null,
                'kyc_document_back_path' => null,
                'kyc_selfie_path' => null,
            ])->save();

            $user->savedIbans()->delete();
            $user->consentRecords()->delete();
            $user->notifications()->delete();
            $user->wallet()->delete();

            Log::info('Account anonymized successfully', ['user_id' => $userId]);
        });

        // Right to erasure (GDPR Art. 17) extends to the stored ID-card scans
        // and the selfie (biometric data, special category under Art. 9) —
        // nulling the DB reference is not enough, the files must go too.
        if ($kycFiles !== []) {
            Storage::disk('local')->delete($kycFiles);
        }
    }
}
