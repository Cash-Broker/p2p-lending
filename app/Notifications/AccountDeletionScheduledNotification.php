<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SEC-22: the closure is confirmed and scheduled; the owner can still cancel. Mail only, sync. */
class AccountDeletionScheduledNotification extends Notification
{
    use Queueable;

    public function __construct(public CarbonInterface $scheduledFor, public string $cancelUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->scheduledFor->copy()->timezone('Europe/Sofia')->format('d.m.Y');

        return (new MailMessage)
            ->subject("Закриването на акаунта е планирано за {$date} — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Потвърдихте закриването на акаунта си. То ще бъде извършено не по-рано от {$date}.")
            ->line('Тогава личните ви данни ще бъдат анонимизирани. Финансовите записи се запазват за регулаторни цели, а документите за самоличност и съгласията — в ограничен архив за срока по ЗМИП.')
            ->line('До тази дата можете да се откажете — от профила си («Отмени закриването») или от бутона по-долу.')
            ->action('Отмени закриването', $this->cancelUrl)
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'account_deletion_scheduled', 'scheduled_for' => $this->scheduledFor->toIso8601String()];
    }
}
