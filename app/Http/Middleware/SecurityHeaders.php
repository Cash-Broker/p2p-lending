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

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
