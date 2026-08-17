<?php

namespace App\Notifications\Concerns;

use NotificationChannels\WebPush\WebPushMessage;

/**
 * Shared Web Push plumbing for notification classes (2026-08-17).
 *
 * Conventions (agreed with Yordan):
 * - The channel is simply appended to via() — WebPushChannel no-ops for
 *   notifiables without stored subscriptions, so no conditional is needed.
 * - Lockscreen hygiene: bodies carry the recipient's OWN amounts and loan
 *   numbers only. Admin-facing pushes never include investor names or IBANs
 *   (the tap lands in the panel where the details live behind auth).
 * - `tag` collapses same-topic notifications instead of stacking.
 */
trait SendsWebPush
{
    private function webPushMessage(string $title, string $body, string $url, ?string $tag = null): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/logo/logo-mark.png')
            ->badge('/logo/logo-mark.png')
            ->data(['url' => $url])
            // A day: transactional pushes older than that are stale — the
            // in-app bell and email carry the durable record.
            ->options(['TTL' => 86400]);

        if ($tag !== null) {
            $message->tag($tag);
        }

        return $message;
    }
}
