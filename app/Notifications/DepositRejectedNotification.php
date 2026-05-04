<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DepositRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(private string $amount, private ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Депозит отхвърлен — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашият депозит от {$this->amount} € е отхвърлен.");
        if ($this->reason) $mail->line("Причина: {$this->reason}");
        return $mail->line('Моля, свържете се с нас за повече информация.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'deposit_rejected', 'amount' => $this->amount, 'reason' => $this->reason];
    }
}
