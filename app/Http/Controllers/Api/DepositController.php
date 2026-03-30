<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepositRequestResource;
use App\Models\DepositRequest;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function __construct(private DepositService $depositService) {}

    /**
     * Get deposit info: bank details and user's reference code.
     * If no pending deposit exists, create one so the user always has a reference code.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Find or create a pending deposit request for the reference code
        $pendingDeposit = DepositRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (! $pendingDeposit) {
            $pendingDeposit = $this->depositService->createRequest($user->id, '0.00');
        }

        return response()->json([
            'reference_code' => $pendingDeposit->reference_code,
            'bank_details' => [
                'bank_name' => 'P2P Invest Bank',
                'iban' => 'BG80BNBG96611020345678',
                'bic' => 'BNBGBGSD',
                'beneficiary' => 'P2P Invest ООД',
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $deposits = DepositRequest::where('user_id', $request->user()->id)
            ->where('amount', '>', 0)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => DepositRequestResource::collection($deposits),
            'meta' => [
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
                'per_page' => $deposits->perPage(),
                'total' => $deposits->total(),
            ],
        ]);
    }
}
