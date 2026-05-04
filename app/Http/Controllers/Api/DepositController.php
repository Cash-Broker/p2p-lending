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
     * Return the user's active deposit code + bank details.
     *
     * The reference code is a per-DepositRequest random DEP-XXXXXXXX value,
     * not a sequential function of user_id. The same code is reused across
     * /api/deposit calls until it expires (30 days) or gets approved/rejected,
     * at which point a fresh code is minted. The user pastes this code into
     * the bank wire reference; admin matches the wire to the user via the
     * code (not by guessing from sender name).
     */
    public function index(Request $request): JsonResponse
    {
        $deposit = $this->depositService->getOrCreateActiveCode($request->user()->id);

        return response()->json([
            'reference_code' => $deposit->reference_code,
            'expires_at' => $deposit->expires_at,
            'bank_details' => [
                'bank_name' => 'Postbank',
                'iban' => 'BG33BPBI79421429061401',
                'bic' => 'BPBIBGSF',
                'beneficiary' => 'ВАМА АСЕТ ЕООД',
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        // Filter `amount > 0` excludes the placeholder rows that
        // getOrCreateActiveCode creates with NULL amount — investors only
        // see deposits that actually happened, not their unused codes.
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
