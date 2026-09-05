<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SEC-22: the closure request is gone. Text by cause:
 * self | link («не съм аз») | admin (+reason) | password_reset | blocked (+reason).
 * Mail only, sync.
 */
class AccountDeletionCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public string $by, public ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Заявката за закриване на акаунта е отменена — Vamaasset')
            ->greeting("Здравейте, {$notifiable->name}!");

        match ($this->by) {
            'link' => $mail
                ->line('Заявката за закриване беше отменена чрез линка «Не съм аз». За ваша сигурност всички активни сесии, токени и устройства за известия на акаунта бяха прекратени.')
                ->line('Ако не сте подавали заявката, сменете паролата си сега:')
                ->action('Смяна на паролата', config('app.url').'/forgot-password'),
            'admin' => $mail
                ->line('Заявката за закриване беше отменена от администратор.')
                ->line('Причина: '.($this->reason ?: '—')),
            'password_reset' => $mail
                ->line('Заявката за закриване беше отменена автоматично след смяната на паролата ви.'),
            'blocked' => $mail
                ->line('Закриването не можа да бъде извършено: '.($this->reason ?: 'акаунтът вече не отговаря на условията.'))
                ->line('Заявката е отменена. При желание я подайте отново от профила си, когато условията са изпълнени.'),
            default => $mail
                ->line('Заявката за закриване на акаунта беше отменена по ваше желание. Акаунтът ви остава активен.'),
        };

        return $mail->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'account_deletion_cancelled', 'by' => $this->by];
    }
}
