<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\InvestorRegisteredAdminNotification;
use App\Services\TelegramService;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
 * The crossing is `>=`, not `==`, and it is claimed with `Cache::add` (review
 * 2026-08-20 found both). Two registrations committing between two counts
 * would make every observer see 12, and an equality test would then skip the
 * summary entirely — silence with nobody told; while a paced drip that keeps
 * the rolling count sitting on exactly 11 would re-send the summary, and its
 * non-silent Telegram ping, over and over. The marker also holds the promise
 * the summary makes: individual alerts do not resume for the rest of the
 * window even if the count falls back below the threshold.
 *
 * Subscribed via Event::listen in AppServiceProvider — the only registration
 * path in this app. Laravel's automatic discovery of app/Listeners is off
 * (bootstrap/app.php, `->withEvents(discover: false)`): both mechanisms were
 * live until 2026-08-20 and every listener fired twice.
 */
class SendInvestorRegisteredAlert
{
    /** Registrations in the window at which alerting collapses into one summary. */
    private const CONSOLIDATION_THRESHOLD = 11;

    /** Rolling window over which registrations are counted (seconds). */
    private const WINDOW_SECONDS = 3600;

    /** Written once per window when the summary goes out; present = stay quiet. */
    private const BURST_MARKER = 'registration_burst_notice';

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
        // A summary already went out for this window: it promised silence, so
        // stay silent whatever the count does from here — including the bell,
        // which would otherwise bury the panel inbox under a scripted burst.
        if (Cache::has(self::BURST_MARKER)) {
            return;
        }

        $windowCount = $this->registrationsInWindow();
        $isBurst = $windowCount >= self::CONSOLIDATION_THRESHOLD;

        // Claim the summary. add() succeeds for exactly one caller, so a race
        // between two crossings sends one summary, not two. When it fails we
        // back off only if the marker is genuinely there — with a broken cache
        // has() is false too, and a burst nobody is told about is a worse
        // outcome than a repeated warning.
        if ($isBurst && ! Cache::add(self::BURST_MARKER, 1, self::WINDOW_SECONDS) && Cache::has(self::BURST_MARKER)) {
            return;
        }

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
