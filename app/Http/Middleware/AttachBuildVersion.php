<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every API response with the current frontend build fingerprint
 * (X-Build = md5 of the Vite manifest). The SPA's axios layer compares it
 * against the build it booted with and silently reloads on the next route
 * navigation when they diverge — so users on stale tabs pick up a deploy
 * within minutes, without anyone "clearing caches" (2026-08-15).
 */
class AttachBuildVersion
{
    /** Computed once per process (opcache/фpm worker), not per request. */
    private static ?string $build = null;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Build', self::buildFingerprint());

        return $response;
    }

    private static function buildFingerprint(): string
    {
        if (self::$build === null) {
            $manifest = public_path('build/manifest.json');
            self::$build = is_file($manifest) ? (string) md5_file($manifest) : 'dev';
        }

        return self::$build;
    }
}
