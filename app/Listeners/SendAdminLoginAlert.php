<?php

namespace App\Listeners;

use App\Mail\AdminLoginAlertMail;
use App\Models\AdminTrustedIp;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an email alert every time an admin logs in.
 *
 * - Investor logins are ignored (only admin role triggers the alert).
 * - Failed login attempts never reach this listener (Login event fires only
 *   on successful authentication), so attackers cannot spam the admin's inbox
 *   with bogus alerts.
 * - The IP is checked against `admin_trusted_ips`; the subject line and body
 *   change based on whether the IP is known.
 * - To prevent inbox flooding from rapid legitimate logins (e.g. token refresh,
 *   multi-tab admin work), additional alerts within a 1h window from the same
 *   (admin, IP) tuple are coalesced into a single "X logins in last hour"
 *   email sent at the 11th attempt; further attempts are suppressed until the
 *   cache key expires.
 *
 * Subscribed via Event::listen in AppServiceProvider.
 */
class SendAdminLoginAlert
{
    /**
     * Threshold at which we send a single "consolidated" email instead of one
     * per login. The first login always fires immediately (count == 1).
     */
    private const CONSOLIDATION_THRESHOLD = 11;

    /** Window over which we track repeated logins from the same IP. */
    private const WINDOW_SECONDS = 3600;

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->isAdmin()) {
            return;
        }

        $request = request();
        $ipAddress = $request?->ip() ?? '0.0.0.0';
        $userAgent = $request?->userAgent();
        $occurredAt = Carbon::now();

        $isKnownIp = AdminTrustedIp::query()
            ->where('user_id', $user->id)
            ->where('ip_address', $ipAddress)
            ->exists();

        // Touch last_seen_at on every login from a known IP.
        if ($isKnownIp) {
            AdminTrustedIp::where('user_id', $user->id)
                ->where('ip_address', $ipAddress)
                ->update(['last_seen_at' => $occurredAt]);
        }

        $cacheKey = sprintf('admin_login_alert:%d:%s', $user->id, $ipAddress);
        $count = Cache::increment($cacheKey);
        if ($count === 1) {
            // First hit — set the TTL.
            Cache::put($cacheKey, 1, self::WINDOW_SECONDS);
        }

        $consolidated = $count >= self::CONSOLIDATION_THRESHOLD;

        // Send only the first login or the threshold-crossing consolidated email.
        if ($count === 1 || $count === self::CONSOLIDATION_THRESHOLD) {
            try {
                Mail::to($user->email)->queue(new AdminLoginAlertMail(
                    admin: $user,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                    occurredAt: $occurredAt,
                    isKnownIp: $isKnownIp,
                    consolidatedCount: $consolidated ? $count : 1,
                ));
            } catch (\Throwable $e) {
                // Email failure must not break the login response.
                Log::warning('Admin login alert dispatch failed', [
                    'admin_id' => $user->id,
                    'ip' => $ipAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
