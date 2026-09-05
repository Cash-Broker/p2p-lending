<?php

namespace App\Services;

use App\Exceptions\AdminReauthenticationException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Step-up check for sensitive admin actions (audit 2026-09-01 SEC-11, owner
 * 2026-09-03): the admin types their own password again before a secret is
 * revealed. Same primitives and limits as the login limiter (LoginRequest):
 * 5 FAILURES per 15 minutes, cleared on the first success.
 *
 * The key is per ADMIN, not per IP, on purpose: the password being guessed is
 * the admin's own, and a hijacked session changes IP freely. The 5th failure
 * is an account-takeover signal on an already-authenticated session, so it
 * goes to Telegram 🟠 (id only, no name — lockscreen hygiene).
 *
 * RateLimiter::hit() does Cache::add() before increment, so the database
 * cache-store footgun from CLAUDE.md does not apply here.
 */
final class AdminReauthenticationService
{
    public const MAX_FAILURES = 5;

    public const DECAY_SECONDS = 900;

    public function __construct(private TelegramService $telegram) {}

    /**
     * @throws AdminReauthenticationException
     */
    public function verify(User $admin, string $password, string $purpose): void
    {
        $key = "admin-reauth:{$admin->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $seconds = RateLimiter::availableIn($key);
            Log::warning('Admin re-authentication locked out', ['admin_id' => $admin->id, 'purpose' => $purpose, 'retry_after' => $seconds]);

            throw new AdminReauthenticationException(
                "Твърде много грешни опити. Опитайте отново след {$seconds} сек.",
                $seconds,
            );
        }

        if ($password === '' || ! Hash::check($password, (string) $admin->password)) {
            $hits = RateLimiter::hit($key, self::DECAY_SECONDS);
            Log::warning('Admin re-authentication failed', ['admin_id' => $admin->id, 'purpose' => $purpose, 'failures' => $hits]);

            if ($hits >= self::MAX_FAILURES) {
                try {
                    $this->telegram->high(
                        'Заключен повторен вход в админ панела',
                        "Админ #{$admin->id} сгреши паролата си {$hits} пъти ({$purpose}). Показването е заключено за 15 минути.",
                        ['admin_id' => $admin->id, 'purpose' => $purpose],
                    );
                } catch (Throwable) {
                    // The alert must never change the outcome of the check.
                }
            }

            throw new AdminReauthenticationException('Паролата не съвпада.');
        }

        RateLimiter::clear($key);
    }
}
