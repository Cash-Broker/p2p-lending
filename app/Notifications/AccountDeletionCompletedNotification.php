<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SEC-22: sent on demand to the ORIGINAL address after the anonymisation
 * committed (the row's e-mail is `deleted_{id}@…` by then). Carries only the
 * user id — the row no longer has a name.
 */
class AccountDeletionCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Акаунтът ви е закрит — Vamaasset')
            ->greeting('Здравейте!')
            ->line("Акаунт #{$this->userId} беше закрит според заявката ви. Личните данни са анонимизирани.")
            ->line('Финансовите записи се пазят за регулаторно изисквания срок; документите за самоличност и съгласията — в ограничен архив за срока по ЗМИП, след което се заличават автоматично.')
            ->salutation('Благодарим ви, че бяхте с нас. Екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'account_deletion_completed', 'user_id' => $this->userId];
    }
}
