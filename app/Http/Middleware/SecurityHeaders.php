<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security headers to every response.
 *
 * X-Frame-Options: DENY — prevents clickjacking (embedding our site in an iframe)
 * X-Content-Type-Options: nosniff — prevents browser from guessing MIME types
 * Referrer-Policy: strict-origin-when-cross-origin — limits referrer data leakage
 * X-XSS-Protection: 0 — disabled because modern CSP replaces it, and the filter
 *   itself can introduce XSS vectors in some browsers
 * Strict-Transport-Security — forces HTTPS for 1 year (added only in production)
 * Permissions-Policy — disables camera, microphone, geolocation access
 * X-Robots-Tag — pre-launch hard-block for search engines + AI crawlers.
 *   Defence-in-depth on top of robots.txt and the noindex meta tag in HTML.
 *   Toggle off via SEO_INDEXABLE=true in env at launch (after legal review).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Pre-launch crawler block. The env flag defaults to FALSE so the
        // header stays in place even if someone forgets to copy the env var
        // to a new server — fail-safe orientation. To go live, set
        // SEO_INDEXABLE=true in the production .env and redeploy.
        if (! config('app.seo_indexable', false)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
