<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The investor met the bonus condition and the money became spendable
 * (Reni 2026-08-18). Sent after the release commits — a failed send only
 * logs, the money stays released.
 */
class BonusReleasedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(private string $amount, private string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Бонусът ви е освободен — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Изпълнихте условието и бонусът от {$this->amount} € вече може да се тегли.");

        if ($this->reason !== '') {
            $message->line("Основание: {$this->reason}");
        }

        return $message
            ->line('Сумата е при свободните ви средства — можете да я изтеглите или да я инвестирате отново.')
            ->action('Виж баланс', config('app.url').'/dashboard')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bonus_released',
            'amount' => $this->amount,
            'reason' => $this->reason,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Бонусът ви е освободен: {$this->amount} €",
            'Сумата вече може да се тегли.',
            config('app.url').'/dashboard',
        );
    }
}
