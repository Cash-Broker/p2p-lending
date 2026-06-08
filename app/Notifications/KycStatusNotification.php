<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $status) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->status === 'approved') {
            return (new MailMessage)
                ->subject('KYC верификация одобрена — Vamaasset')
                ->greeting("Здравейте, {$notifiable->name}!")
                ->line('Вашата KYC верификация е одобрена.')
                ->line('Вече имате пълен достъп до платформата — можете да депозирате, инвестирате и теглите средства.')
                ->action('Започни да инвестираш', config('app.url') . '/invest')
                ->salutation('Поздрави, екипът на Vamaasset');
        }

        return (new MailMessage)
            ->subject('KYC верификация отхвърлена — Vamaasset')
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line('Вашата KYC верификация е отхвърлена.')
            ->line('Моля, качете нови снимки на личната си карта — и от двете страни (отпред и отзад) — с по-добро качество.')
            ->action('Качи нов документ', config('app.url') . '/profile')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'kyc_' . $this->status];
    }
}
