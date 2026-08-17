<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

/**
 * Delivers ONE rendered push payload to ONE device, on the queue.
 *
 * Why a job and not the package's synchronous channel (review 2026-08-17):
 * several senders run inside DB transactions holding row locks — e.g.
 * `UserResource::transitionKycStatus` notifies INSIDE `DB::transaction` under
 * a `users` `lockForUpdate`. A synchronous push meant (a) a live HTTPS
 * round-trip to Google/Mozilla while holding that lock, and (b) any throw
 * from the push library rolling the KYC approval back AFTER the investor's
 * approval email had already gone out. Money/state paths must never depend on
 * a third-party notification service.
 *
 * The payload is pre-rendered scalars — no notification/model serialization,
 * so a queued job can never resurrect stale entity state.
 */
class DeliverWebPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Push services are flaky; back off rather than hammer. */
    public array $backoff = [30, 300];

    /**
     * @param  string  $payload  JSON body the service worker receives
     * @param  array<string, mixed>  $options  TTL/urgency/topic
     */
    public function __construct(
        private int $subscriptionId,
        private string $payload,
        private array $options = [],
    ) {}

    public function handle(WebPush $webPush): void
    {
        $subscription = PushSubscription::find($this->subscriptionId);

        // Unsubscribed (logout, device revoked) between dispatch and delivery.
        if (! $subscription) {
            return;
        }

        try {
            $webPush->queueNotification(
                new Subscription(
                    $subscription->endpoint,
                    $subscription->public_key,
                    $subscription->auth_token,
                    $subscription->content_encoding ?? ContentEncoding::aes128gcm,
                ),
                $this->payload,
                $this->options,
            );

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                // 404/410 — the browser dropped this subscription for good.
                if ($report->isSubscriptionExpired()) {
                    $subscription->delete();

                    continue;
                }

                Log::warning('Web push delivery failed', [
                    'subscription_id' => $subscription->id,
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (Throwable $e) {
            // Unusable credentials (malformed p256dh/auth) can never succeed —
            // retrying would burn the queue forever, so drop the row instead.
            $subscription->delete();

            Log::warning('Web push subscription discarded as unusable', [
                'subscription_id' => $this->subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Web push job failed', [
            'subscription_id' => $this->subscriptionId,
            'error' => $e?->getMessage(),
        ]);
    }
}
