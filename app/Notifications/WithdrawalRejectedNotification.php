<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalRejectedNotification extends Notification
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
            ->subject("Теглене отхвърлено — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашето теглене от {$this->amount} € е отхвърлено.");
        if ($this->reason) $mail->line("Причина: {$this->reason}");
        return $mail->line('Средствата са върнати в акаунта ви.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'withdrawal_rejected', 'amount' => $this->amount, 'reason' => $this->reason];
    }
}
