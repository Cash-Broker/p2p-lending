<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Investor-facing alert for an admin-granted promotional bonus.
 * Sent synchronously AFTER the money is committed (deposit-notification
 * pattern: notification failure logs, never rolls back money).
 */
class BonusCreditedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(private string $amount, private string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Получихте бонус: {$this->amount} €",
            // Admin free text stays OUT of the lockscreen — it is in the app.
            'Отворете приложението за детайли.',
            config('app.url').'/dashboard',
        );
    }
}
