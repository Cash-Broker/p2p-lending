<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Investor-facing alert for a PARTIAL early closure (Reni 2026-08-18): the
 * borrower returned part of the principal, so the platform closed the same
 * share of this investor's position and paid the interest earned on it.
 *
 * Separate from {@see EarlyRepaymentReceivedNotification} because the loan
 * lives on: it has no `early_repaid_at` to dedupe against, and a borrower may
 * close «колкото пъти иска» — a calendar cooldown would silently swallow the
 * second closure of the same day. Dedupe is therefore keyed on the closure id.
 *
 * PII hygiene, as with every loan notification: no borrower data, only the
 * loan id and the investor's own numbers.
 */
class LoanPartiallyClosedNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public int $loanId,
        public int $closureId,
        public string $principal,
        public string $interest,
        public string $total,
        public CarbonInterface $asOf,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->wasAlreadySent($notifiable)) {
            return [];
        }

        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    /** One notification per (investor, closure) — retries must not double-send. */
    private function wasAlreadySent(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'notifications')) {
            return false;
        }

        return $notifiable->notifications()
            ->where('type', static::class)
            ->whereJsonContains('data->closure_id', $this->closureId)
            ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Частично погасяване по кредит #{$this->loanId} — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Кредитополучателят погаси предсрочно част от кредит #{$this->loanId}.")
            ->line("Върната главница: {$this->principal} €")
            ->line("Лихва за периода до {$this->asOf->format('d.m.Y')}: {$this->interest} €")
            ->line("Общо постъпили по сметката ви: {$this->total} €")
            ->line('Останалата част от инвестицията ви продължава по същия график — вноските са '
                .'намалени пропорционално, а падежите остават непроменени.')
            ->action('Виж портфейла', config('app.url').'/portfolio')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'loan_partially_closed',
            'loan_id' => $this->loanId,
            'closure_id' => $this->closureId,
            'principal' => $this->principal,
            'interest' => $this->interest,
            'total' => $this->total,
            'as_of' => $this->asOf->toDateString(),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Частично погасяване: +{$this->total} €",
            "Кредит #{$this->loanId} — част от инвестицията ви е върната.",
            config('app.url').'/portfolio',
        );
    }
}
