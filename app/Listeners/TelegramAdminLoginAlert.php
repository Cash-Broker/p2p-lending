<?php

namespace App\Listeners;

use App\Models\AdminTrustedIp;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Auth\Events\Login;

/**
 * Mirrors AdminLoginAlertMail to Telegram (HIGH tier).
 *
 * Fires on every successful admin login. Investor logins are ignored.
 *
 * Distinguishes trusted vs untrusted IPs (same logic as the email alert) and
 * adjusts the message accordingly. Untrusted IP logins are still HIGH tier
 * (not CRITICAL) — admin's normal travel / new networks should not wake them
 * up at night. CRITICAL is reserved for failed-login spikes (see
 * TelegramFailedLoginAlert) which actually indicate attack.
 */
class TelegramAdminLoginAlert
{
    public function __construct(private TelegramService $telegram) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->isAdmin()) {
            return;
        }

        if (! $this->telegram->isConfigured()) {
            return;
        }

        $request = request();
        $ip = $request?->ip() ?? 'unknown';
        $userAgent = $request?->userAgent() ?? 'unknown';

        // The admin_trusted_ips table has no `confirmed_at` column; the
        // mere presence of a row is what marks the IP as trusted.
        $isTrusted = AdminTrustedIp::query()
            ->where('user_id', $user->id)
            ->where('ip_address', $ip)
            ->exists();

        $title = $isTrusted ? 'Admin вход (доверен IP)' : 'Admin вход — НЕпознат IP';

        $body = $isTrusted
            ? 'Успешен вход в админ панела от познат IP адрес.'
            : 'Успешен вход в админ панела от НОВ IP адрес. Ако не си ти, незабавно смени паролата + ' .
              'провери „Trusted IPs" списъка в админ профила.';

        $this->telegram->high($title, $body, [
            'admin' => $user->email,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 120),
            'trusted_ip' => $isTrusted ? 'да' : 'НЕ',
        ]);
    }
}
