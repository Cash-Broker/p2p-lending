<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Internal-control alert: every bonus grant is announced to the OTHER
 * admins (the actor already knows). A bonus mints spendable balance with
 * no bank wire behind it — the one money-in path a single admin can
 * trigger alone — so a second pair of eyes gets an immediate, unerasable
 * email trail (mirrors the KYC/withdrawal event-alert pattern,
 * 2026-08-07 «когато има какво, без час»).
 *
 * Queued + mail-only, dispatched AFTER the money committed; a failed
 * send logs and never rolls back the grant.
 */
class BonusGrantedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $grantedByName,
        private string $investorName,
        private string $amount,
        private string $reason,
        private string $grantedAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Начислен бонус {$this->amount} € — {$this->investorName} — Vamaasset Admin")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Администратор {$this->grantedByName} начисли бонус от {$this->amount} € на {$this->investorName}.")
            ->line("Основание: {$this->reason}")
            ->line("Час: {$this->grantedAt}")
            ->line('Бонусите са платформен маркетингов разход — нямат банков превод зад себе си и не участват в съпоставката с банковото извлечение.')
            ->action('Транзакции', url('/admin/transactions'))
            ->salutation('Vamaasset Admin');
    }
}
