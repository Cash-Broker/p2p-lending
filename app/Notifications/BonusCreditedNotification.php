<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Investor-facing alert for a granted bonus.
 * Sent synchronously AFTER the money is committed (deposit-notification
 * pattern: notification failure logs, never rolls back money).
 *
 * The condition is spelled out here on purpose (Reni 2026-08-18): the bonus is
 * visible in the account from this moment, so the message that announces it is
 * the moment we tell the investor what it takes to cash it. Anything less and
 * we are showing money we do not intend to let them withdraw.
 */
class BonusCreditedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        private string $amount,
        private string $reason,
        private string $baseAmount,
        private int $requiredInstallments,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Получихте бонус — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Начислен ви е бонус от {$this->amount} €.")
            ->line("Основание: {$this->reason}")
            ->line('**Как се освобождава бонусът:** сумата се вижда в профила ви, но става свободна за теглене, '
                ."след като инвестирате общо {$this->baseAmount} € и получите {$this->requiredInstallments} "
                .'погашения по тези инвестиции. Инвестициите може да са в различни кредити. '
                .'При план „Капитализация“ бонусът се освобождава на падежа на кредита.')
            ->line('Освобождаването е автоматично — не е нужно да заявявате нищо.')
            ->action('Виж баланс', config('app.url').'/dashboard')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bonus_credited',
            'amount' => $this->amount,
            'reason' => $this->reason,
            'base_amount' => $this->baseAmount,
            'required_installments' => $this->requiredInstallments,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Получихте бонус: {$this->amount} €",
            // Admin free text stays OUT of the lockscreen — it is in the app.
            'Отворете приложението за условията по освобождаването.',
            config('app.url').'/dashboard',
        );
    }
}
