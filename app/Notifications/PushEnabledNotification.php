<?php

namespace App\Notifications;

use App\Notifications\Channels\QueuedWebPushChannel;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The «здравей» confirmation (Yordan 2026-08-17): sent the moment a device is
 * registered, so the person SEES that notifications actually work instead of
 * trusting a green label — the one end-to-end proof that needs no test setup.
 *
 * Push ONLY and deliberately unrecorded: no mail, no bell row. It says
 * nothing but "this channel is alive", so a durable copy would be noise
 * («няма как да го запишем» — correct, and it shouldn't be).
 *
 * Fires only for a NEWLY created subscription — the SPA and the admin panel
 * re-assert ownership on every load, and confirming those would spam.
 */
class PushEnabledNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function via(object $notifiable): array
    {
        return [QueuedWebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $isAdmin = method_exists($notifiable, 'isAdmin') && $notifiable->isAdmin();

        return $this->webPushMessage(
            'Известията са включени ✅',
            $isAdmin
                ? 'Ще получавате известия при нови инвестиции, KYC заявки и тегления.'
                : 'Ще получавате известия при изплатени лихви, депозити и тегления.',
            config('app.url').($isAdmin ? '/admin' : '/dashboard'),
            // Role-scoped tag: Reni holds an admin AND an investor account on
            // one phone, and a shared tag made the second confirmation REPLACE
            // the first in the tray (2026-08-17).
            $isAdmin ? 'push-enabled-admin' : 'push-enabled-investor',
        );
    }
}
