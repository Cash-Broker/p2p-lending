<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Cache;

/**
 * Listens to Auth::Failed events and dispatches CRITICAL Telegram alerts on
 * suspicious patterns:
 *
 *   1. Attempt to log in with an email that EXISTS but with wrong password —
 *      counted per (email + IP). 5+ within 10 minutes → alert.
 *
 *   2. Attempt to log in with an email that does NOT exist in the users
 *      table — likely scanner bot / credential stuffing. Counted per IP.
 *      5+ within 10 minutes → alert.
 *
 * Threshold is 5 to balance signal vs. noise. Single typo failures are
 * common and not alerted. Sustained brute-force is.
 *
 * Cache keys expire after the window, so a benign user who later logs in
 * does not stay flagged forever.
 */
class TelegramFailedLoginAlert
{
    private const WINDOW_SECONDS = 600; // 10 minutes
    private const ALERT_THRESHOLD = 5;

    public function __construct(private TelegramService $telegram) {}

    public function handle(Failed $event): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $request = request();
        $ip = $request?->ip() ?? 'unknown';
        $email = $event->credentials['email'] ?? '(no-email)';
        $userExists = $event->user instanceof User;
        $isAdminAttempt = $userExists && $event->user->isAdmin();

        $key = $userExists
            ? "telegram:failed-login:user:{$email}:ip:{$ip}"
            : "telegram:failed-login:no-user:ip:{$ip}";

        $count = Cache::get($key, 0) + 1;
        Cache::put($key, $count, self::WINDOW_SECONDS);

        // Alert exactly once per window when threshold is crossed.
        if ($count !== self::ALERT_THRESHOLD) {
            return;
        }

        if ($isAdminAttempt) {
            $this->telegram->critical(
                'Brute-force на admin login',
                "5+ неуспешни опита за вход с admin email от един IP в последните 10 минути.\n"
                .'Възможна целенасочена атака — провери дали admin паролата е силна и обмислй промяна.',
                [
                    'admin_email' => $email,
                    'attacker_ip' => $ip,
                    'user_agent' => $request?->userAgent() ?? 'unknown',
                ],
            );
        } elseif ($userExists) {
            $this->telegram->critical(
                'Brute-force на инвеститорски акаунт',
                "5+ неуспешни опита за вход в съществуващ инвеститорски акаунт от един IP за 10 мин.\n"
                .'Възможно компрометиране на акаунт.',
                [
                    'investor_email' => $email,
                    'attacker_ip' => $ip,
                ],
            );
        } else {
            $this->telegram->critical(
                'Scanner / credential stuffing',
                "5+ неуспешни опита за вход с несъществуващ email от един IP в 10 минути.\n"
                .'Вероятно автоматичен scanner или credential stuffing атака. Провери fail2ban логовете.',
                [
                    'attacker_ip' => $ip,
                    'last_attempted_email' => $email,
                ],
            );
        }
    }
}
