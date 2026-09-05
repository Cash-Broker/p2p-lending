<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * SEC-22: an investor pressed «Не съм аз» on a deletion request — a possible
 * account takeover. Admins: queued mail + push (id only on the lockscreen).
 */
class AccountDeletionDisownedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(public int $userId, public string $userName) {}

    public function via(object $notifiable): array
    {
        return ['mail', QueuedWebPushChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("[Vamaasset] Отменено закриване с «Не съм аз» — потребител #{$this->userId}")
            ->greeting('Здравей, '.($notifiable->name ?? 'администратор').',')
            ->line("Инвеститор #{$this->userId} ({$this->userName}) отмени заявка за закриване на акаунта чрез линка «Не съм аз».")
            ->line('Заявката най-вероятно не е била негова — възможен компрометиран акаунт. Всички сесии на акаунта са прекратени и инвеститорът е посъветван да смени паролата си.')
            ->action('Отвори профила', config('app.url')."/admin/users/{$this->userId}")
            ->salutation('Vamaasset');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'account_deletion_disowned', 'user_id' => $this->userId];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->webPushMessage(
            'Възможен компрометиран акаунт',
            "Инвеститор #{$this->userId} отмени закриване с «Не съм аз».",
            config('app.url')."/admin/users/{$this->userId}",
            "deletion-disowned-{$this->userId}",
        );
    }
}
