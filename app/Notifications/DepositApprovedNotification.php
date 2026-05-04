<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DepositApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(private string $amount, private string $referenceCode) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Депозит одобрен — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашият депозит от {$this->amount} € (ref: {$this->referenceCode}) е одобрен.")
            ->line('Средствата са налични в акаунта ви.')
            ->action('Виж баланс', config('app.url') . '/dashboard')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'deposit_approved', 'amount' => $this->amount, 'reference_code' => $this->referenceCode];
    }
}
