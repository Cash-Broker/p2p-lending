<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalRequest as WithdrawalFormRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WithdrawalRequestResource;
use App\Models\SavedIban;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawalService) {}

    public function store(WithdrawalFormRequest $request): JsonResponse
    {
        // Resolve IBAN: either from saved IBAN (server-side, never exposed) or raw input
        if ($request->filled('saved_iban_id')) {
            $savedIban = SavedIban::where('id', $request->saved_iban_id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $iban = $savedIban->iban;
        } else {
            $iban = $request->iban;
        }

        $withdrawal = $this->withdrawalService->createRequest(
            $request->user()->id,
            number_format((float) $request->amount, 2, '.', ''),
            $iban
        );

        return response()->json([
            'message' => 'Withdrawal request created successfully.',
            'withdrawal' => new WithdrawalRequestResource($withdrawal),
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
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
        return response()->json(new WalletResource($request->user()->wallet));
    }
}
