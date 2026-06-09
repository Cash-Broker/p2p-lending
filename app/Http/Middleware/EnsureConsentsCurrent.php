<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks sensitive actions (KYC submission, invest, withdraw) when the user has
 * not accepted the current version of the Terms / Privacy Policy.
 *
 * This is the enforcement half of the re-consent flow: even if the client never
 * shows the re-consent modal, the API refuses the action with a machine-readable
 * 403 the SPA can intercept. Read-only browsing is intentionally NOT gated.
 */
class EnsureConsentsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $pending = $user->outstandingConsents();

            if ($pending !== []) {
                return response()->json([
                    'error' => 'consent_required',
                    'message' => 'Необходимо е да приемете обновените Общи условия и Политика за поверителност, преди да продължите.',
                    'pending' => $pending,
                ], 403);
            }
        }

        return $next($request);
    }
}
