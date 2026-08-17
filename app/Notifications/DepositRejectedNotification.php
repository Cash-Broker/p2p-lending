<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class DepositRejectedNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(private string $amount, private ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Депозит отхвърлен — Vamaasset')
            ->greeting("Здравейте, {$notifiable->name}!")
            ->line("Вашият депозит от {$this->amount} € е отхвърлен.");
        if ($this->reason) {
            $mail->line("Причина: {$this->reason}");
        }

        return $mail->line('Моля, свържете се с нас за повече информация.')
            ->salutation('Поздрави, екипът на Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'deposit_rejected', 'amount' => $this->amount, 'reason' => $this->reason];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            'Депозитът е отказан',
            // Admin free text stays OUT of the lockscreen — it is in the app.
            'Вижте детайлите в приложението.',
            config('app.url').'/deposit',
        );
    }
}
