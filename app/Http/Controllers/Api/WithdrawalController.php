<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalRequest as WithdrawalFormRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WithdrawalRequestResource;
use App\Models\SavedIban;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawalService) {}

    public function store(WithdrawalFormRequest $request): JsonResponse
    {
        $this->authorize('create', WithdrawalRequest::class);
        // Resolve IBAN: either from saved IBAN (server-side, never exposed) or raw input
        if ($request->filled('saved_iban_id')) {
            $savedIban = SavedIban::where('id', $request->saved_iban_id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $iban = $savedIban->iban;
        } else {
            $iban = $request->iban;
        }

        try {
            // bcmath-safe ingress — never float-cast user input.
            $amount = Money::normalizePositive($request->amount);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $withdrawal = $this->withdrawalService->createRequest(
            $request->user()->id,
            $amount,
            $iban
        );

        return response()->json([
            'message' => 'Withdrawal request created successfully.',
            'withdrawal' => new WithdrawalRequestResource($withdrawal),
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WithdrawalRequest::class);

        $withdrawals = WithdrawalRequest::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => WithdrawalRequestResource::collection($withdrawals),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Wallet::class);

        return response()->json(new WalletResource($request->user()->wallet));
    }
}
