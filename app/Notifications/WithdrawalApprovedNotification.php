<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(private string $amount) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Теглене одобрено — {$this->amount} € — P2P Invest")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашето теглене от {$this->amount} € е одобрено.")
            ->line('Средствата ще бъдат преведени по банковата ви сметка в рамките на 1-2 работни дни.')
            ->salutation('Поздрави, екипът на P2P Invest');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'withdrawal_approved', 'amount' => $this->amount];
    }
}
