<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-facing DIGEST notification: summary of newly-detected buyback
 * eligible loans plus an age-breakdown reminder for loans aging in the
 * Queue.
 *
 * One notification per cron run (Q21) — NOT one per eligible loan.
 * Dispatched only when at least 1 loan is actionable (newly-eligible OR
 * aging > 3 days); an empty-state run writes metrics but sends no email
 * (Q21: no empty daily spam).
 *
 * Channels: mail + database. The database payload carries the Filament
 * envelope (`format => filament` via toDatabase()) so the row is visible
 * in the admin panel's bell inbox — without that key Filament's inbox
 * query (`data->format = 'filament'`) filters the row out entirely.
 *
 * Duplicate-delivery honesty (2026-08-07 correction — the original
 * docblock overpromised): for a ShouldQueue notification Laravel
 * resolves via() ONCE at dispatch time and bakes the channel list into
 * per-channel queue jobs — via() is never re-consulted on the worker,
 * so the wasRecentlyNotified() check CANNOT suppress queue-worker
 * retries. What actually holds:
 *   - a single dispatch can never produce two database rows: the row id
 *     is the notification UUID fixed at dispatch, so a retried insert
 *     hits the primary key. Two RACING dispatches mint distinct UUIDs
 *     and could each land a row — the via() check narrows, but cannot
 *     fully close, that window (mitigated here by Cache::lock on the
 *     cron and the per-run run_at);
 *   - a retried MAIL job after the SMTP server already accepted the
 *     message CAN re-send the email — accepted as harmless for a daily
 *     digest (at-least-once beats a silently lost email);
 *   - the via() check only suppresses a REPEATED DISPATCH of the same
 *     run_at whose database row already landed — belt-and-braces, since
 *     each cron run generates a distinct run_at anyway.
 *
 * Constructor snapshot pattern (F1 convention): all rendering data is
 * frozen at dispatch time. Queue worker that delivers hours later sees
 * the same counts + loan IDs as were current at cron run time — no
 * re-query against mutated state.
 *
 * Contract guard: toDatabase() MUST keep `run_at` as an ISO-8601 string
 * and `format => filament` in the stored payload — the dedupe query in
 * via() and the Filament inbox filter depend on them. Pinned by
 * BuybackEligibleAdminNotificationTest.
 */
class BuybackEligibleAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $newlyEligibleCount  loans flagged in THIS cron run (new today)
     * @param  int  $waitingMoreThan3DaysCount  loans flagged > 3 days ago AND still pending
     * @param  int[]  $loanIds  IDs of the newly-flagged loans (for email listing)
     * @param  CarbonInterface  $runAt  the cron run timestamp (dedupe key)
     */
    public function __construct(
        public int $newlyEligibleCount,
        public int $waitingMoreThan3DaysCount,
        public array $loanIds,
        public CarbonInterface $runAt,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasRecentlyNotified($notifiable)) {
            return [];
        }

        return ['mail', 'database'];
    }

    /**
     * Match any existing database-channel row of THIS class for THIS admin
     * with THIS exact run_at ISO string. Runs at DISPATCH time only (see
     * class docblock) — suppresses a repeated dispatch of an already-
     * delivered run_at, nothing more.
     */
    private function wasRecentlyNotified(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        return $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->run_at', $this->runAt->toIso8601String())
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $newly = $this->newlyEligibleCount;
        $subject = sprintf(
            '[Vamaasset] %d %s buyback-eligible %s — Buyback Queue',
            $newly,
            $newly === 1 ? 'нов' : 'нови',
            $newly === 1 ? 'кредит' : 'кредита',
        );

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.buyback-eligible-admin', [
                'adminName' => $notifiable->name ?? 'администратор',
                'newlyCount' => $this->newlyEligibleCount,
                'olderCount' => $this->waitingMoreThan3DaysCount,
                'loanIds' => $this->loanIds,
                'runAtFormatted' => $this->runAt->format('d.m.Y H:i'),
                'queueUrl' => config('app.url').'/admin/buyback-queue',
            ]);
    }

    /**
     * Database channel payload: the Filament envelope (so the row shows
     * up in the admin panel's bell inbox) merged with the snapshot data.
     *
     * **Contract** (do not break — dependents listed):
     *   - `run_at` present as an ISO-8601 string (from
     *     Carbon::toIso8601String()) — via()'s dedupe query matches it
     *     as an exact string;
     *   - `format` === 'filament' — Filament's DatabaseNotifications
     *     inbox filters on `data->format`, rows without it are invisible.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            FilamentNotification::make()
                ->title(sprintf(
                    'Buyback Queue: %d %s · %d %s > 3 дни',
                    $this->newlyEligibleCount,
                    $this->newlyEligibleCount === 1 ? 'нов' : 'нови',
                    $this->waitingMoreThan3DaysCount,
                    $this->waitingMoreThan3DaysCount === 1 ? 'чака' : 'чакат',
                ))
                ->icon('heroicon-o-banknotes')
                ->info()
                ->getDatabaseMessage(),
            $this->toArray($notifiable),
        );
    }

    /**
     * Snapshot data (also merged into toDatabase()). Stable field names
     * for any future UI that renders them.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyback_eligible_admin_digest',
            'run_at' => $this->runAt->toIso8601String(),
            'newly_eligible_count' => $this->newlyEligibleCount,
            'waiting_more_than_3_days_count' => $this->waitingMoreThan3DaysCount,
            'loan_ids' => $this->loanIds,
        ];
    }
}
