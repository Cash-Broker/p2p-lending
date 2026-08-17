<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    public function handle(): void
    {
        $subscription = PushSubscription::find($this->subscriptionId);

        // Unsubscribed (logout, device revoked) between dispatch and delivery.
        if (! $subscription) {
            return;
        }

        // ⚠ The client is built HERE, not injected: the package binds VAPID
        // only CONTEXTUALLY for its own channel
        // (`$app->when(WebPushChannel::class)->needs(WebPush::class)`), so
        // container injection into this job yields a client with NO VAPID —
        // every push then goes out unsigned and the push services reject it
        // silently (review 2026-08-17: nothing was being delivered at all).
        $webPush = $this->client();

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
        } catch (InvalidArgumentException $e) {
            // THIS subscription's own credentials are unusable (malformed
            // p256dh/auth): retrying can never help, so drop the row.
            $this->discard($subscription, $e);
        } catch (Throwable $e) {
            // OUR breakage — a misconfigured VAPID pair, a transport failure.
            // Never punish the device for the server's fault: deleting here
            // would mass-unsubscribe every user on one bad config value
            // (caught by tests, 2026-08-17). Let the queue retry instead…
            //
            // …but a shape-valid-yet-unusable key (an off-curve p256dh throws
            // RuntimeException from the agreement-key step, not
            // InvalidArgumentException) would retry forever. On the LAST
            // attempt, drop such a device rather than keep it immortal.
            if ($this->attempts() >= $this->tries) {
                $this->discard($subscription, $e);

                return;
            }

            throw $e;
        }
    }

    private function discard(PushSubscription $subscription, Throwable $e): void
    {
        $subscription->delete();

        Log::warning('Web push subscription discarded as unusable', [
            'subscription_id' => $this->subscriptionId,
            'endpoint_host' => parse_url((string) $subscription->endpoint, PHP_URL_HOST),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * A WebPush client carrying our VAPID identity — mirrors the package's
     * own contextual binding (WebPushServiceProvider::boot).
     */
    private function client(): WebPush
    {
        $vapid = config('webpush.vapid');
        $auth = [];

        if (! empty($vapid['public_key']) && ! empty($vapid['private_key'])) {
            $auth['VAPID'] = [
                'publicKey' => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
                'subject' => $vapid['subject'] ?: url('/'),
            ];
        } else {
            // Sending unsigned would be silently rejected by every push
            // service — fail loudly so the misconfiguration is visible.
            Log::error('Web push VAPID keys are not configured — push cannot be delivered');
        }

        return (new WebPush($auth, [], 30, config('webpush.client_options', [])))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding'));
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Web push job failed', [
            'subscription_id' => $this->subscriptionId,
            'attempts' => $this->attempts(),
            'exception' => $e ? $e::class : null,
            'error' => $e?->getMessage(),
        ]);
    }
}
