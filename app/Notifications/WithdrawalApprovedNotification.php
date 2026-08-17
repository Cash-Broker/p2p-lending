<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class WithdrawalApprovedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(private string $amount) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Теглене одобрено — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашето теглене от {$this->amount} € е одобрено.")
            ->line('Средствата ще бъдат преведени по банковата ви сметка в рамките на 1-2 работни дни.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'withdrawal_approved', 'amount' => $this->amount];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Тегленето е изпълнено: {$this->amount} €",
            'Сумата е наредена към вашата сметка.',
            config('app.url').'/transactions',
        );
    }
}
