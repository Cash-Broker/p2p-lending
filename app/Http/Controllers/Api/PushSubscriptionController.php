<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebPushNotification;
use App\Models\User;
use App\Notifications\PushEnabledNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Web Push subscription management (2026-08-17).
 *
 * A subscription belongs to the AUTHENTICATED user — that is the entire
 * authorization model: whoever proves a session owns the rows they create,
 * and the senders decide by ROLE AT SEND TIME who receives what (an investor
 * forging a subscribe call simply subscribes their own investor account —
 * admin events are dispatched only to role=admin users). Works for both the
 * SPA (Sanctum stateful) and the Filament session (same cookie guard).
 *
 * Hardening after review 2026-08-17:
 * - endpoint host must be a KNOWN push service. The server later POSTs to
 *   whatever is stored here, so an unrestricted field is an outbound-request
 *   primitive (blind SSRF / fanout) handed to any authenticated user.
 * - lengths match the COLUMN sizes (endpoint 500) so an over-long value is a
 *   422, never a 500 + CRITICAL Telegram alert.
 * - keys are validated as base64url of the exact decoded sizes the push
 *   crypto requires (p256dh = 65-byte uncompressed P-256 point, auth = 16
 *   bytes); garbage used to poison the row and break later sends.
 * - a per-user device cap keeps the table (and the per-notification fanout)
 *   bounded.
 */
class PushSubscriptionController extends Controller
{
    /** endpoint column is string(500). */
    private const ENDPOINT_MAX = 500;

    /** Devices one account may register. Beyond this the oldest is dropped. */
    private const MAX_DEVICES_PER_USER = 10;

    /**
     * Hosts (exact or suffix) of the browser push services we support.
     * Chrome/Edge/Android → FCM, Firefox → Mozilla autopush, Safari → Apple,
     * legacy Edge → WNS.
     */
    private const ALLOWED_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        '.push.services.mozilla.com',
        'web.push.apple.com',
        '.notify.windows.com',
        '.push.apple.com',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => [
                'required', 'string', 'max:'.self::ENDPOINT_MAX, 'url:https',
                fn (string $attribute, mixed $value, callable $fail) => $this->assertKnownPushService($value, $fail),
            ],
            'keys' => ['required', 'array'],
            // 65 raw bytes → 87 base64url chars (unpadded) / 88 with padding,
            // AND the point must lie on P-256: a shape-valid but off-curve key
            // is accepted by the length check yet breaks the crypto layer at
            // send time (review 2026-08-17).
            'keys.p256dh' => ['required', 'string', 'max:255', $this->base64UrlBytes(65), $this->onCurveP256()],
            // 16 raw bytes → 22 base64url chars (unpadded) / 24 with padding.
            'keys.auth' => ['required', 'string', 'max:255', $this->base64UrlBytes(16)],
            // True only for a user-initiated enrolment (banner / pill /
            // Профил). The silent ownership re-asserts pass false so a
            // handover never fires a visible «здравей».
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        // Own upsert, NOT the package's updatePushSubscription() — that one
        // steals the device from any other account (see the model docblock).
        $subscription = $user->registerPushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        $this->pruneOldestDevices($user->id);

        // «Здравей» — confirm on THIS device that delivery works. Two guards:
        // only a user-initiated enrolment (`confirm`) and only a freshly
        // created row; and it is dispatched to the ONE new subscription, not
        // via notify() which would fan out to every device the person owns
        // (review 2026-08-17).
        if ($subscription->wasRecentlyCreated && ($validated['confirm'] ?? false)) {
            $this->confirmOnDevice($user, $subscription);
        }

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.self::ENDPOINT_MAX],
        ]);

        // Scoped delete: the trait's deletePushSubscription removes the
        // endpoint only when it belongs to THIS user — no cross-user reach.
        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['subscribed' => false]);
    }

    /** Reject endpoints the platform would never legitimately push to. */
    private function assertKnownPushService(mixed $value, callable $fail): void
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        if ($host === '') {
            $fail('The :attribute is not a valid push endpoint.');

            return;
        }

        foreach (self::ALLOWED_ENDPOINT_HOSTS as $allowed) {
            $isSuffix = str_starts_with($allowed, '.');

            if ($isSuffix ? str_ends_with($host, $allowed) : $host === $allowed) {
                return;
            }
        }

        $fail('The :attribute is not a supported push service.');
    }

    /** Send the «здравей» to exactly the device that just enrolled. */
    private function confirmOnDevice(User $user, PushSubscription $subscription): void
    {
        $message = (new PushEnabledNotification)->toWebPush($user);

        DeliverWebPushNotification::dispatch(
            $subscription->id,
            json_encode($message->toArray(), JSON_UNESCAPED_UNICODE),
            $message->getOptions(),
        );
    }

    /**
     * The p256dh must be a point that actually lies on the P-256 curve.
     * Wrapping the raw point in a SubjectPublicKeyInfo DER and asking OpenSSL
     * to import it is the cheapest real check — an off-curve blob fails here
     * instead of exploding later in the push crypto.
     */
    private function onCurveP256(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $point = base64_decode(strtr((string) $value, '-_', '+/'), true);

            if ($point === false || strlen($point) !== 65 || $point[0] !== "\x04") {
                $fail('The :attribute is not an uncompressed P-256 point.');

                return;
            }

            // Fixed ASN.1 prefix for id-ecPublicKey + prime256v1, 65-byte point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

            if (openssl_pkey_get_public($pem) === false) {
                $fail('The :attribute is not a valid P-256 public key.');
            }
        };
    }

    /** base64url string decoding to exactly $bytes raw bytes. */
    private function base64UrlBytes(int $bytes): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($bytes): void {
            $value = (string) $value;

            if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
                $fail('The :attribute is not valid base64url.');

                return;
            }

            $decoded = base64_decode(strtr($value, '-_', '+/'), true);

            if ($decoded === false || strlen($decoded) !== $bytes) {
                $fail("The :attribute must decode to {$bytes} bytes.");
            }
        };
    }

    /**
     * Keep at most MAX_DEVICES_PER_USER rows per account, newest first — one
     * person's browser churn must not grow the per-notification fanout
     * without bound.
     */
    private function pruneOldestDevices(int $userId): void
    {
        $keepIds = PushSubscription::query()
            ->where('subscribable_type', User::class)
            ->where('subscribable_id', $userId)
            ->latest('id')
            ->limit(self::MAX_DEVICES_PER_USER)
            ->pluck('id');

        PushSubscription::query()
            ->where('subscribable_type', User::class)
            ->where('subscribable_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
