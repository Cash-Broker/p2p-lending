<?php

namespace App\Notifications;

use App\Models\SavedIban;
use App\Services\WithdrawalService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * SEC-01: «Потвърдете нов IBAN». Mail + bell, deliberately NO push — the link
 * must not travel over a lockscreen. Synchronous, like every other investor
 * mail that carries a link (delivery must not depend on the queue worker).
 * The plain token lives only in this mail; the DB holds its hash.
 */
class SavedIbanConfirmationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $savedIbanId,
        private string $maskedIban,
        private ?string $label,
        private string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function confirmationUrl(): string
    {
        return URL::temporarySignedRoute(
            'ibans.confirm',
            now()->addMinutes(SavedIban::CONFIRMATION_TTL_MINUTES),
            ['iban' => $this->savedIbanId, 'token' => $this->plainToken],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = WithdrawalService::newIbanCooldownHours();
        $label = $this->label ? " ({$this->label})" : '';

        return (new MailMessage)
            ->subject('Потвърдете нов IBAN — Vamaasset')
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Към профила ви е добавена банкова сметка {$this->maskedIban}{$label}.")
            ->line('Потвърдете я от бутона по-долу. Линкът е валиден 60 минути.')
            ->action('Потвърди IBAN', $this->confirmationUrl())
            ->line("Тегления към тази сметка ще са възможни {$hours} ч. след потвърждаването.")
            ->line('Ако не сте добавяли IBAN — НЕ натискайте бутона, сменете паролата си незабавно и ни пишете.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'iban_confirmation',
            'saved_iban_id' => $this->savedIbanId,
            'masked_iban' => $this->maskedIban,
        ];
    }
}
