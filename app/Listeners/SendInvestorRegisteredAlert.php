<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\InvestorRegisteredAdminNotification;
use App\Services\TelegramService;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Carbon;

/**
 * Admin alert on a new investor registration (Reni 2026-08-20 — «за нови
 * регистрации на инвеститори може ли да получавам известия»).
 *
 * Fan-out mirrors the KYC / withdrawal / investment alerts: a synchronous
 * Filament bell (notifyNow — the panel must not depend on a live queue
 * worker) plus the queued mail + Web Push notification, each dispatch
 * wrapped so one failure never suppresses the rest and NEVER fails the
 * registration itself. Plus the shared-channel Telegram record, exactly
 * like «Нова инвестиция».
 *
 * FLOOD GUARD — the reason this listener has more logic than the others.
 * /api/register is the first PUBLIC trigger for an admin alert: KYC and
 * withdrawals need a session, this needs nothing but the form. The
 * endpoint's own throttle (10/min per IP, routes/api.php) still allows
 * hundreds of accounts per hour, and each one would otherwise be an email
 * plus a push plus a bell row per admin. So: the first
 * CONSOLIDATION_THRESHOLD-1 registrations of a rolling hour alert
 * individually, the threshold-crossing one sends a single "N нови
 * регистрации" summary (Telegram 🟠 — a burst on a public form is worth a
 * non-silent ping), and the rest of that hour stays quiet. The Users list
 * and the 09:00 digest remain the complete record.
 *
 * The window is counted from `users.created_at`, deliberately NOT from a
 * cache counter: Cache::increment on a missing key returns false with the
 * database store, which is exactly what left the admin-login alert dead in
 * production (audit 2026-07-02, see SendAdminLoginAlert).
 *
 * REGISTRATION — read before adding an Event::listen line for this class.
 * Laravel auto-discovers listeners in app/Listeners, so this handler is
 * already subscribed by being here. Adding the explicit Event::listen that
 * the older listeners use would register it TWICE and every new account
 * would alert the admins twice. (Those older ones — SendAdminLoginAlert,
 * TelegramAdminLoginAlert, TelegramFailedLoginAlert — are in exactly that
 * state today: `app('events')->getRawListeners()` shows each of them twice,
 * so every admin login already sends two identical Telegram messages.
 * Reported to Yordan 2026-08-20; fixing it is a separate change because it
 * has to pick ONE mechanism for all of them.)
 */
class SendInvestorRegisteredAlert
{
    /** Registration number within the window that switches to one summary. */
    private const CONSOLIDATION_THRESHOLD = 11;

    /** Rolling window over which registrations are counted (seconds). */
    private const WINDOW_SECONDS = 3600;

    public function __construct(private TelegramService $telegram) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        // Admin accounts are not created through this flow today; if that
        // ever changes, an admin must not page the admins.
        if (! $user instanceof User || $user->isAdmin()) {
            return;
        }

        try {
            $this->alert($user);
        } catch (\Throwable $e) {
            // A notification must never turn a successful registration into
            // a 500 — the account and its consent records are committed.
            report($e);
        }
    }

    private function alert(User $user): void
    {
        $windowCount = $this->registrationsInWindow();

        // Past the summary, the hour stays quiet — including the bell, which
        // would otherwise bury the panel inbox under a scripted burst.
        if ($windowCount > self::CONSOLIDATION_THRESHOLD) {
            return;
        }

        $isBurst = $windowCount === self::CONSOLIDATION_THRESHOLD;

        try {
            $admins = User::where('role', 'admin')->get();
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $registeredAt = Carbon::now();

        foreach ($admins as $admin) {
            // notifyNow: the panel bell must not depend on a queue worker
            // (same deliberate choice as the KYC/withdrawal/investment
            // bells). The name is e()-escaped — Filament renders bell bodies
            // as sanitized HTML, not escaped text, so a raw name could
            // smuggle a live link into the admin panel.
            $this->safeNotify($admin, FilamentNotification::make()
                ->title($isBurst ? 'Повишен брой регистрации' : 'Нова регистрация')
                ->body($isBurst
                    ? $windowCount.' нови профила за последния час. Отделните известия са спрени до края на часа.'
                    : e($user->name).' си създаде инвеститорски профил.')
                ->icon('heroicon-o-user-plus')
                ->info()
                ->actions([
                    FilamentAction::make('view')
                        ->label($isBurst ? 'Потребители' : 'Преглед')
                        ->url($isBurst ? url('/admin/users') : url('/admin/users/'.$user->id))
                        ->markAsRead(),
                ])
                ->toDatabase(), now: true);

            $this->safeNotify($admin, new InvestorRegisteredAdminNotification(
                investorId: $user->id,
                investorName: $user->name,
                investorEmail: $user->email,
                accountType: $user->account_type,
                registeredAt: $registeredAt,
                consolidatedCount: $isBurst ? $windowCount : 1,
            ));
        }

        // Shared-channel record — TelegramService is a no-op when
        // unconfigured and never throws. A burst on a public form is worth
        // waking someone (🟠); a single registration is not (🟡 silent).
        if ($isBurst) {
            $this->telegram->high(
                'Повишен брой регистрации',
                $windowCount.' нови профила за последния час.',
                ['Известия' => 'спрени до края на часа'],
            );

            return;
        }

        $this->telegram->info(
            'Нова регистрация',
            "{$user->name} си създаде инвеститорски профил.",
            [
                'Имейл' => $user->email,
                'Тип' => $user->isLegalEntity() ? 'Юридическо лице' : 'Физическо лице',
            ],
        );
    }

    /** Investor accounts created inside the rolling window, this one included. */
    private function registrationsInWindow(): int
    {
        return User::where('role', 'investor')
            ->where('created_at', '>=', Carbon::now()->subSeconds(self::WINDOW_SECONDS))
            ->count();
    }

    private function safeNotify(User $admin, $notification, bool $now = false): void
    {
        try {
            $now ? $admin->notifyNow($notification) : $admin->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
