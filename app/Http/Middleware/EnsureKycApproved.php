<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks financial operations (deposit, invest, withdraw) for users
 * without approved KYC status. Email verification alone is not enough —
 * KYC (Know Your Customer) is a regulatory requirement for any platform
 * that handles money. Without it, we can't verify the user's identity
 * and we're exposed to money laundering / fraud liability.
 */
class EnsureKycApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->kyc_status !== 'approved') {
            return response()->json([
                'message' => 'KYC verification required for financial operations.',
                'kyc_status' => $user?->kyc_status,
                'requires_kyc' => true,
            ], 403);
        }

        return $next($request);
    }
}
