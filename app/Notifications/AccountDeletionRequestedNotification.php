<?php

namespace App\Notifications;

use App\Services\AccountDeletionService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SEC-22 (owner 2026-09-03): «someone asked to close this account». MAIL ONLY
 * and synchronous — bell/push would reach the attacker's own session/device;
 * e-mail is the out-of-band channel, and the worker may be down.
 */
class AccountDeletionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $confirmUrl,
        public string $cancelUrl,
        private CarbonInterface $requestedAt,
        private ?string $ipAddress,
        private ?string $userAgent,
        private int $waitingDays = AccountDeletionService::DEFAULT_WAITING_DAYS,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->requestedAt->copy()->timezone('Europe/Sofia')->format('d.m.Y H:i');

        return (new MailMessage)
            ->subject('Заявка за закриване на акаунта — Vamaasset')
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("На {$when} ч. от IP {$this->ipAddress} беше подадена заявка за закриване на вашия акаунт.")
            ->line(sprintf(
                '**Ако сте вие:** потвърдете от бутона по-долу (линкът е валиден %d часа). След потвърждението акаунтът се закрива след %d-дневен период, през който можете да се откажете.',
                AccountDeletionService::CONFIRM_LINK_TTL_HOURS,
                $this->waitingDays,
            ))
            ->action('Потвърди закриването', $this->confirmUrl)
            ->line('**Ако НЕ сте вие:** не натискайте бутона. Отменете заявката оттук и сменете паролата си незабавно:')
            ->line('[Не съм аз — отмени заявката]('.$this->cancelUrl.')')
            ->line('Устройство: '.mb_substr((string) $this->userAgent, 0, 120))
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'account_deletion_requested'];
    }
}
