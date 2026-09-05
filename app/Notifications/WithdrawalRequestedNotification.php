<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * SEC-01 (owner 2026-09-03): the INVESTOR hears about a withdrawal the moment
 * it is requested — not at approval, when the money has already left. Sent
 * after the request committed, only for a fresh row (never on an idempotent
 * replay). Push carries the amount only: no IBAN, no name (lockscreen hygiene).
 */
class WithdrawalRequestedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        private int $withdrawalId,
        private string $amount,
        private string $maskedIban,
        private ?string $ibanLabel,
        private CarbonInterface $requestedAt,
        private ?string $ipAddress,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->ibanLabel ? " ({$this->ibanLabel})" : '';

        return (new MailMessage)
            ->subject("Заявено теглене — {$this->amount} € — Vamaasset")
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Току-що беше заявено теглене на {$this->amount} € към сметка {$this->maskedIban}{$label}.")
            ->line('Време: '.$this->requestedAt->copy()->timezone('Europe/Sofia')->format('d.m.Y H:i').' ч.'.($this->ipAddress ? " · IP: {$this->ipAddress}" : ''))
            ->line('Заявката се изплаща от администратор след преглед.')
            ->line('Ако не сте вие: сменете паролата си веднага от Профил → Парола и ни пишете.')
            ->action('Към тегленията', config('app.url').'/withdraw')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'withdrawal_requested',
            'withdrawal_id' => $this->withdrawalId,
            'amount' => $this->amount,
            'masked_iban' => $this->maskedIban,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            "Заявка за теглене: {$this->amount} €",
            'Ако не сте вие, сменете паролата си веднага.',
            config('app.url').'/withdraw',
            "withdrawal-{$this->withdrawalId}",
        );
    }
}
