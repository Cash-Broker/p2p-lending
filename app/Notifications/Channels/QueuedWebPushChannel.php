<?php

namespace App\Notifications\Channels;

use App\Jobs\DeliverWebPushNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * Drop-in replacement for the package's WebPushChannel that RENDERS here and
 * DELIVERS on the queue (review 2026-08-17).
 *
 * Two guarantees the synchronous channel could not give:
 *  1. No third-party HTTP on the request path — senders run inside DB
 *     transactions holding row locks (KYC approval, repayments), and a push
 *     service round-trip must never extend a lock or roll back money/state.
 *  2. This channel NEVER throws. A notification is a side effect; a broken
 *     push subscription must not be able to fail the business operation that
 *     triggered it. Failures land in the log (and the job's own retry).
 *
 * Delivery, retry and dead-endpoint pruning live in
 * {@see DeliverWebPushNotification}.
 */
class QueuedWebPushChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        try {
            $subscriptions = $notifiable->routeNotificationFor('WebPush', $notification);

            if (! $subscriptions || $subscriptions->isEmpty()) {
                return;
            }

            /** @var WebPushMessage $message */
            $message = $notification->toWebPush($notifiable, $notification);
            $payload = json_encode($message->toArray(), JSON_UNESCAPED_UNICODE);
            $options = $message->getOptions();

            foreach ($subscriptions as $subscription) {
                DeliverWebPushNotification::dispatch($subscription->id, $payload, $options);
            }
        } catch (Throwable $e) {
            Log::warning('Web push dispatch skipped', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
