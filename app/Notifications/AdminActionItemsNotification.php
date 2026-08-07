<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-facing DIGEST notification: the morning summary's actionable
 * items (KYC awaiting review, deposits awaiting confirmation, withdrawals
 * awaiting processing, buyback queue) delivered by EMAIL, so the admin
 * hears about pending work even without watching Telegram.
 *
 * Dispatched by `telegram:digest` (09:00 cron) ONLY when at least one
 * actionable count is > 0 — an all-clear morning sends no email (same
 * no-empty-daily-spam decision as the F2 buyback digest, Q21). Recipients
 * are admin accounts only (`role = 'admin'`).
 *
 * Late/default loan count is informational context in the email body but
 * deliberately NOT a trigger: a late loan by itself requires no admin
 * action — the actionable derivative is the buyback queue (F2 flags it
 * when due), matching the Telegram digest's needs-attention title rule.
 *
 * Channels: mail + database. The database payload carries the Filament
 * envelope (`format => filament` via toDatabase()) so the row is visible
 * in the admin panel's bell inbox. (The investor-facing loan-event
 * notifications deliberately keep raw toArray() rows instead — their
 * inbox is the Vue SPA via /api/notifications, not the Filament bell.)
 *
 * Duplicate-delivery honesty (same 2026-08-07 contract as the sibling
 * F1/F2 docblocks): for a ShouldQueue notification Laravel resolves via()
 * ONCE at dispatch time and bakes the channel list into per-channel
 * queue jobs — via() is never re-consulted on the worker, so the
 * wasRecentlyNotified() check CANNOT suppress queue-worker retries.
 * What actually holds:
 *   - a single dispatch can never produce two database rows: the row id
 *     is the notification UUID fixed at dispatch, so a retried insert
 *     hits the primary key. Two RACING dispatches mint distinct UUIDs
 *     and could each land a row — the via() check narrows, but cannot
 *     fully close, that window;
 *   - a retried MAIL job after the SMTP server already accepted the
 *     message CAN re-send the email — accepted as harmless for a daily
 *     digest (at-least-once beats a silently lost morning email);
 *   - the via() check only suppresses a REPEATED DISPATCH of the same
 *     run_at whose database row already landed (e.g. an operator
 *     re-running the command for a re-issued digest) — belt-and-braces.
 *
 * Constructor snapshot pattern (F1/F2 convention): all rendering data is
 * frozen at dispatch time. A queue worker that delivers hours later shows
 * the same counts as were current at cron run time — no re-query against
 * mutated state.
 *
 * Contract guard: toDatabase() MUST keep `run_at` as an ISO-8601 string
 * and `format => filament` in the stored payload — the dedupe query in
 * via() and the Filament inbox filter depend on them. Pinned by
 * TelegramDigestAdminEmailTest.
 */
class AdminActionItemsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $kycPending  users with kyc_status = 'submitted'
     * @param  int  $depositsPending  pending deposit requests with a wired amount
     * @param  int  $withdrawalsPending  pending withdrawal requests
     * @param  int  $buybackQueue  loans flagged buyback-eligible, not yet executed/dismissed
     * @param  int  $loansLate  late/default loans (informational, not a trigger)
     * @param  CarbonInterface  $runAt  the cron run timestamp (dedupe key)
     */
    public function __construct(
        public int $kycPending,
        public int $depositsPending,
        public int $withdrawalsPending,
        public int $buybackQueue,
        public int $loansLate,
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
        return (new MailMessage)
            ->subject('[Vamaasset] Задачи за обработка: '.$this->subjectSummary())
            ->markdown('emails.admin-action-items', [
                'adminName' => $notifiable->name ?? 'администратор',
                'kycPending' => $this->kycPending,
                'depositsPending' => $this->depositsPending,
                'withdrawalsPending' => $this->withdrawalsPending,
                'buybackQueue' => $this->buybackQueue,
                'loansLate' => $this->loansLate,
                'runAtFormatted' => $this->runAt->format('d.m.Y H:i'),
                'adminUrl' => config('app.url').'/admin',
            ]);
    }

    /**
     * Compact BG summary of the non-zero actionable counts, e.g.
     * "1 KYC · 2 депозита". Bounded: max 4 segments.
     */
    private function subjectSummary(): string
    {
        $parts = [];
        if ($this->kycPending > 0) {
            $parts[] = $this->kycPending.' KYC';
        }
        if ($this->depositsPending > 0) {
            $parts[] = $this->depositsPending.' '.($this->depositsPending === 1 ? 'депозит' : 'депозита');
        }
        if ($this->withdrawalsPending > 0) {
            $parts[] = $this->withdrawalsPending.' '.($this->withdrawalsPending === 1 ? 'теглене' : 'тегления');
        }
        if ($this->buybackQueue > 0) {
            $parts[] = $this->buybackQueue.' buyback';
        }

        return implode(' · ', $parts);
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
                ->title('Задачи за обработка: '.$this->subjectSummary())
                ->icon('heroicon-o-clipboard-document-list')
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
            'type' => 'admin_action_items_digest',
            'run_at' => $this->runAt->toIso8601String(),
            'kyc_pending' => $this->kycPending,
            'deposits_pending' => $this->depositsPending,
            'withdrawals_pending' => $this->withdrawalsPending,
            'buyback_queue' => $this->buybackQueue,
            'loans_late' => $this->loansLate,
        ];
    }
}
