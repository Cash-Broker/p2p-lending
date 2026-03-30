<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsInvestor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isInvestor()) {
            abort(403, 'This action is restricted to investors.');
        }

        // Financial features require verified email — regulatory requirement
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email address.',
                'requires_verification' => true,
            ], 403);
        }

        return $next($request);
    }
}
