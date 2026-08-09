<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Investor-facing alert for an admin-granted promotional bonus.
 * Sent synchronously AFTER the money is committed (deposit-notification
 * pattern: notification failure logs, never rolls back money).
 */
class BonusCreditedNotification extends Notification
{
    use Queueable;

    public function __construct(private string $amount, private string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Получихте бонус — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Начислен ви е бонус от {$this->amount} €.")
            ->line("Основание: {$this->reason}")
            ->line('Средствата са налични в акаунта ви и могат да се инвестират или изтеглят.')
            ->action('Виж баланс', config('app.url').'/dashboard')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'bonus_credited', 'amount' => $this->amount, 'reason' => $this->reason];
    }
}
