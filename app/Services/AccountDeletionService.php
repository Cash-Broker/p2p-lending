<?php

namespace App\Services;

use App\Exceptions\DeletionLinkInvalidException;
use App\Models\BonusGrant;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AccountDeletionCancelledNotification;
use App\Notifications\AccountDeletionCompletedNotification;
use App\Notifications\AccountDeletionDisownedAdminNotification;
use App\Notifications\AccountDeletionRequestedNotification;
use App\Notifications\AccountDeletionScheduledNotification;
use App\Support\OpsAlert;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * GDPR closure of an investor account.
 *
 * SEC-22 (owner 2026-09-03) turned the old one-click anonymisation into a
 * four-step state machine kept on the users row:
 *   REQUEST  — password, fail-fast eligibility, e-mail with confirm + «не съм аз» links
 *   CONFIRM  — signed link, no session (the mailbox is the second factor);
 *              schedules the closure `account_deletion_waiting_days` ahead
 *   CANCEL   — owner (profile), the mail link (also kicks every session), an
 *              admin, a password reset, or a blocked finalisation
 *   FINALISE — 04:30 cron; re-checks everything under locks, anonymises,
 *              keeps the KYC evidence in the SEC-16 archive, purges sessions
 *
 * Money: nothing moves except the pre-existing forfeit of LOCKED bonuses at
 * finalisation (BonusService::cancel → WalletService). Investor mails are
 * MAIL ONLY and synchronous — a bell or push would reach the attacker's device.
 * Links carry an HMAC of (id|email|requested_at): a cancel or a re-request
 * invalidates every earlier link without a token column.
 */
class AccountDeletionService
{
    public const CONFIRM_LINK_TTL_HOURS = 24;

    public const DEFAULT_WAITING_DAYS = 7;

    public function __construct(
        private BonusService $bonusService,
        private KycRetentionService $retention,
        private TelegramService $telegram,
    ) {}

    public static function waitingDays(): int
    {
        return max(1, (int) PlatformSetting::get('account_deletion_waiting_days', self::DEFAULT_WAITING_DAYS));
    }

    // ── 1. request ────────────────────────────────────────────────────

    public function requestDeletion(User $user, string $currentPassword, ?string $ipAddress = null, ?string $userAgent = null): User
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => ['Грешна парола.']]);
        }

        $locked = DB::transaction(function () use ($user): User {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->deletionState() === 'scheduled') {
                throw ValidationException::withMessages(['account' => [
                    'Закриването вече е планирано за '.$locked->deletion_scheduled_for->copy()->timezone('Europe/Sofia')->format('d.m.Y').'. Можете да го отмените от профила си.',
                ]]);
            }

            // Fail fast with the messages the old one-click flow gave; the real
            // checks run again under locks at finalisation.
            $this->assertEligible($locked, forFinalization: false);

            // A re-request while awaiting confirmation re-stamps: the hash changes,
            // every earlier link dies, a fresh mail goes out.
            $locked->forceFill([
                'deletion_requested_at' => now(),
                'deletion_confirmed_at' => null,
                'deletion_scheduled_for' => null,
            ])->save();

            return $locked;
        });

        Log::info('Account deletion requested', ['user_id' => $locked->id, 'ip_address' => $ipAddress]);

        $this->notifyInvestor($locked, new AccountDeletionRequestedNotification(
            $this->confirmUrl($locked),
            $this->cancelUrl($locked),
            now(),
            $ipAddress,
            $userAgent,
            self::waitingDays(),
        ));

        return $locked;
    }

    // ── 2. confirm ────────────────────────────────────────────────────

    /**
     * @throws DeletionLinkInvalidException when no request is open
     */
    public function confirm(User $user): User
    {
        $justConfirmed = false;

        $locked = DB::transaction(function () use ($user, &$justConfirmed): User {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->deletion_requested_at === null || $locked->deletion_finalized_at !== null) {
                throw new DeletionLinkInvalidException("No open deletion request for user #{$locked->id}.");
            }

            if ($locked->deletion_confirmed_at !== null) {
                return $locked; // second click — idempotent
            }

            // Start of day, so the date every surface prints IS the day the 04:30
            // cron finalises (a confirm at 10:00 + N days would otherwise slip to N+1).
            $locked->forceFill([
                'deletion_confirmed_at' => now(),
                'deletion_scheduled_for' => now()->addDays(self::waitingDays())->startOfDay(),
            ])->save();
            $justConfirmed = true;

            return $locked;
        });

        if ($justConfirmed) {
            $date = $locked->deletion_scheduled_for->copy()->timezone('Europe/Sofia')->format('d.m.Y');
            $this->notifyInvestor($locked, new AccountDeletionScheduledNotification($locked->deletion_scheduled_for, $this->cancelUrl($locked)));
            $this->notifyAdminsBell('Заявено закриване на акаунт', e($locked->name)." потвърди закриване на профила си; планирано за {$date}.", (int) $locked->id);
            $this->telegramSafe(fn () => $this->telegram->info(
                'Заявено закриване на акаунт',
                "Инвеститор #{$locked->id} потвърди закриване; планирано за {$date}.",
                ['user_id' => $locked->id],
            ));
        }

        return $locked;
    }

    // ── 3. cancel ─────────────────────────────────────────────────────

    /**
     * @param  string  $by  self | link | admin | password_reset | blocked
     * @return bool false when there was nothing to cancel
     */
    public function cancel(User $user, string $by, ?string $reason = null): bool
    {
        $cancelled = DB::transaction(function () use ($user, $by): ?User {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->hasOpenDeletionRequest()) {
                return null;
            }

            $locked->forceFill([
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_scheduled_for' => null,
            ])->save();

            if ($by === 'link') {
                // Whoever clicks «Не съм аз» owns the mailbox and may be locking
                // an attacker out — every session and token goes.
                $this->revokeAllSessions($locked);
            }

            return $locked;
        });

        if ($cancelled === null) {
            return false;
        }

        $this->notifyInvestor($cancelled, new AccountDeletionCancelledNotification($by, $reason));

        if ($by === 'link') {
            $this->notifyAdminsBell('Възможен компрометиран акаунт', "Инвеститор #{$cancelled->id} отмени закриване с «Не съм аз»; всички сесии са прекратени.", (int) $cancelled->id);
            foreach (User::where('role', 'admin')->get() as $admin) {
                try {
                    $admin->notify(new AccountDeletionDisownedAdminNotification((int) $cancelled->id, (string) $cancelled->name));
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $this->telegramSafe(fn () => $this->telegram->high(
                'Отменено закриване с «Не съм аз»',
                "Инвеститор #{$cancelled->id} отмени заявка за закриване чрез линка — възможен компрометиран акаунт. Сесиите са прекратени.",
                ['user_id' => $cancelled->id],
            ));
        }

        return true;
    }

    // ── 4. finalise ───────────────────────────────────────────────────

    /**
     * @return string finalized | skipped | blocked
     */
    public function finalize(User $user): string
    {
        $userId = (int) $user->id;
        $originalEmail = null;
        $kycFiles = [];

        try {
            $outcome = DB::transaction(function () use ($userId, &$originalEmail, &$kycFiles): string {
                $locked = User::whereKey($userId)->lockForUpdate()->firstOrFail();

                if (! $locked->isInvestor()) {
                    throw new InvalidArgumentException("Only investor accounts can be closed (user #{$userId}).");
                }

                if ($locked->deletion_confirmed_at === null
                    || $locked->deletion_finalized_at !== null
                    || $locked->deletion_scheduled_for === null
                    || $locked->deletion_scheduled_for->isFuture()) {
                    return 'skipped'; // cancelled meanwhile, double run, or not due yet
                }

                $originalEmail = $locked->email;
                $kycFiles = array_filter([
                    $locked->kyc_document_front_path,
                    $locked->kyc_document_back_path,
                    $locked->kyc_selfie_path,
                ]);

                $this->anonymize($locked);

                return 'finalized';
            }, 3); // deadlock retry: the money paths lock wallet→request rows in the other order; the closure is idempotent
        } catch (ValidationException $e) {
            // Money arrived during the window: nothing was anonymised (rolled back).
            // The request is cancelled with the reason — not retried forever.
            $this->retention->discardCopies($userId);
            $reason = (string) collect($e->errors())->flatten()->first();
            $this->cancel(User::findOrFail($userId), 'blocked', $reason);

            return 'blocked';
        } catch (Throwable $e) {
            $this->retention->discardCopies($userId);

            throw $e;
        }

        if ($outcome !== 'finalized') {
            return $outcome;
        }

        // The originals leave kyc-documents/ only after the commit; the retained
        // copies live under kyc-retained/ until the ЗМИП clock (KycRetentionService).
        if ($kycFiles !== []) {
            // The local disk has throw => false: the boolean is the only signal, and a
            // silently orphaned ID scan would make the erasure claim false.
            $deleted = Storage::disk('local')->delete($kycFiles);
            if (! $deleted) {
                $leftovers = array_values(array_filter($kycFiles, fn (string $path) => Storage::disk('local')->exists($path)));
                Log::error('Account closure: original KYC files could NOT be deleted', ['user_id' => $userId, 'paths' => $leftovers]);
                OpsAlert::mail(
                    'KYC файлове не са изтрити при закриване на акаунт',
                    "Акаунт #{$userId} е закрит, но оригиналните KYC файлове не можаха да бъдат изтрити от kyc-documents/: ".implode(', ', $leftovers).'. Изтрий ги ръчно.',
                );
                $this->telegramSafe(fn () => $this->telegram->high(
                    'KYC файлове не са изтрити',
                    "Акаунт #{$userId}: оригиналните документи останаха в kyc-documents/ — виж имейла.",
                    ['user_id' => $userId],
                ));
            }
        }
        Log::info('Account anonymized successfully', ['user_id' => $userId]);

        try {
            if ($originalEmail) {
                Notification::route('mail', $originalEmail)->notify(new AccountDeletionCompletedNotification($userId));
            }
        } catch (Throwable $e) {
            report($e);
        }
        $this->notifyAdminsBell('Акаунт е закрит', "Акаунт #{$userId} беше закрит според заявката на инвеститора.", $userId);

        return 'finalized';
    }

    // ── links ─────────────────────────────────────────────────────────

    public function linkHash(User $user): string
    {
        $stamp = $user->deletion_requested_at?->getTimestamp() ?? 0;

        return hash_hmac('sha256', $user->id.'|'.$user->email.'|'.$stamp, (string) config('app.key'));
    }

    public function linkHashMatches(User $user, string $hash): bool
    {
        return $user->deletion_requested_at !== null && hash_equals($this->linkHash($user), $hash);
    }

    public function confirmUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'account.deletion.confirm',
            now()->addHours(self::CONFIRM_LINK_TTL_HOURS),
            ['user' => $user->id, 'hash' => $this->linkHash($user)],
        );
    }

    public function cancelUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'account.deletion.cancel',
            now()->addDays(self::waitingDays() + 2),
            ['user' => $user->id, 'hash' => $this->linkHash($user)],
        );
    }

    // ── internals ─────────────────────────────────────────────────────

    /**
     * The anonymisation body, run inside finalize()'s transaction under the
     * users row lock. Every guard re-runs here under its own lock.
     */
    private function anonymize(User $user): void
    {
        $userId = (int) $user->id;

        // Conditional bonuses: money the investor could never withdraw — forfeit
        // them FIRST, otherwise the balance check would trap the account
        // (Reni 2026-08-18).
        foreach (BonusGrant::where('user_id', $userId)->locked()->lockForUpdate()->get() as $grant) {
            try {
                $this->bonusService->cancel($grant, null, 'Закрит акаунт (GDPR изтриване)');
            } catch (InvalidArgumentException $e) {
                Log::warning('Could not forfeit conditional bonus on account closure', [
                    'user_id' => $userId,
                    'bonus_grant_id' => $grant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->assertEligible($user, forFinalization: true);

        // SEC-16: archive BEFORE the anonymising write — the snapshot needs the
        // real name/e-mail/phone and the consent rows deleted below.
        $retention = $this->retention->retain($user);

        // Placeholder deposit codes (no wire yet) are retired so the code can
        // never be credited to a closed account.
        $user->depositRequests()
            ->where('status', 'pending')
            ->whereNull('amount')
            ->update([
                'status' => 'rejected',
                'admin_note' => 'Автоматично отхвърлен: акаунтът е закрит (GDPR изтриване).',
            ]);

        $this->revokeAllSessions($user);

        $user->forceFill([
            'name' => "Изтрит потребител #{$userId}",
            // RFC 2606 reserved TLD: nobody can ever receive mail (a reset link) for it.
            'email' => "deleted_{$userId}@deleted.invalid",
            'phone' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'email_verified_at' => null,
            'kyc_status' => 'pending',
            'kyc_document_front_path' => null,
            'kyc_document_back_path' => null,
            'kyc_selfie_path' => null,
            'deletion_finalized_at' => now(),
        ])->save();

        $user->savedIbans()->delete();
        $user->consentRecords()->delete();
        $user->notifications()->delete();
        $user->pushSubscriptions()->delete();
        $user->wallet()->delete();

        Log::info('KYC retained', [
            'user_id' => $userId,
            'kyc_retention_id' => $retention?->id,
            'retained_until' => $retention?->retained_until?->toDateString(),
        ]);
    }

    private function assertEligible(User $user, bool $forFinalization): void
    {
        $walletQuery = Wallet::where('user_id', $user->id);
        $wallet = $forFinalization ? $walletQuery->lockForUpdate()->first() : $walletQuery->first();

        if ($wallet && bccomp((string) $wallet->invested, '0', 2) > 0) {
            throw ValidationException::withMessages(['account' => ['Не може да изтриете акаунта си докато имате активни инвестиции.']]);
        }

        // At request time a LOCKED bonus sits inside `available` but is not
        // withdrawable — it must not trap the investor; it is forfeited at
        // finalisation, where the raw balance is what counts.
        if ($wallet) {
            $spendable = $forFinalization
                ? (string) $wallet->available
                : bcsub((string) $wallet->available, $this->lockedBonusTotal((int) $user->id), 2);
            if (bccomp($spendable, '0', 2) > 0) {
                throw ValidationException::withMessages(['account' => ['Моля, изтеглете наличния си баланс преди да изтриете акаунта.']]);
            }

            if (bccomp((string) $wallet->reserved, '0', 2) > 0) {
                throw ValidationException::withMessages(['account' => ['Имате резервирани средства за чакащо теглене. Изчакайте да бъде обработено.']]);
            }
        }

        $deposits = $user->depositRequests()->where('status', 'pending')->where('amount', '>', 0);
        $withdrawals = $user->withdrawalRequests()->where('status', 'pending');
        $approved = $user->withdrawalRequests()->where('status', 'approved');
        if ($forFinalization) {
            $deposits->lockForUpdate();
            $withdrawals->lockForUpdate();
            $approved->lockForUpdate();
        }

        if ($deposits->count() > 0 || $withdrawals->count() > 0) {
            throw ValidationException::withMessages(['account' => ['Имате чакащи заявки за депозит или теглене. Изчакайте да бъдат обработени.']]);
        }

        if ($approved->count() > 0) {
            throw ValidationException::withMessages(['account' => ['Имате одобрено теглене, което още не е изплатено. Изчакайте преводът да бъде потвърден.']]);
        }
    }

    private function lockedBonusTotal(int $userId): string
    {
        return BonusGrant::where('user_id', $userId)->locked()->get()
            ->reduce(fn (string $carry, BonusGrant $grant) => bcadd($carry, (string) $grant->amount, 2), '0.00');
    }

    private function revokeAllSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        } else {
            Log::info('Session revocation skipped: session driver is not database', [
                'user_id' => $user->id,
                'driver' => config('session.driver'),
            ]);
        }

        $user->tokens()->delete();
        // A push subscription is a standing, session-independent channel to ONE
        // device — an attacker's phone keeps receiving the account's financial
        // pushes otherwise (review 2026-09-05).
        $user->pushSubscriptions()->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    private function notifyInvestor(User $user, object $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notifyAdminsBell(string $title, string $body, int $userId): void
    {
        try {
            foreach (User::where('role', 'admin')->get() as $admin) {
                $admin->notifyNow(FilamentNotification::make()
                    ->title($title)
                    ->body($body)
                    ->warning()
                    ->actions([FilamentAction::make('view')->label('Преглед')->url(url('/admin/users/'.$userId))->markAsRead()])
                    ->toDatabase());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function telegramSafe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // best-effort — never affects the outcome
        }
    }
}
