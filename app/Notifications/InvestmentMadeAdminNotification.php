<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Admin alert for every NEW investment (boss 2026-08-10: «когато някой
 * инвестира ще получавам известия, нали»). Mirrors the KYC/withdrawal
 * event-alert pattern: queued mail to all admins, dispatched AFTER the
 * money committed; a failed send logs and never touches the investment.
 *
 * Constructor snapshot pattern: scalars frozen at dispatch time. No PII
 * beyond the investor's name — details live in the admin panel.
 */
class InvestmentMadeAdminNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        private int $investmentId,
        private string $investorName,
        private string $amount,
        private int $loanId,
        private string $planLabel,
        private string $interestRate,
    ) {}

    public function via(object $notifiable): array
    {
        // WebPush no-ops for admins without registered devices.
        return ['mail', QueuedWebPushChannel::class];
    }

    /**
     * The «машинката дрънна» push (2026-08-17). Lockscreen hygiene: amount,
     * loan and plan only — the investor's NAME deliberately stays out of the
     * notification; it is one tap away behind admin auth.
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Нова инвестиция: {$this->amount} €",
            "Кредит #{$this->loanId} · {$this->planLabel} ({$this->interestRate}%)",
            url('/admin/investments'),
            "investment-{$this->investmentId}",
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Нова инвестиция {$this->amount} € — кредит #{$this->loanId} — Vamaasset Admin")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("{$this->investorName} инвестира {$this->amount} € в кредит #{$this->loanId}.")
            ->line("План: {$this->planLabel} ({$this->interestRate}% годишно). Инвестиция № {$this->investmentId}.")
            ->action('Всички инвестиции', url('/admin/investments'))
            ->salutation('Vamaasset Admin');
    }
}
