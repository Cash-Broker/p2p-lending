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
     * /api/deposit calls until an admin approves/rejects a deposit against
     * it — only then is a fresh code minted. Codes do not expire: the user
     * may have wired money against the code before the transfer lands, so
     * retiring an unused code would strand a real bank transfer. The user
     * pastes this code into the bank wire reference; admin matches the wire
     * to the user via the code (not by guessing from sender name).
     *
     * `expires_at` stays in the response for API-shape compatibility with
     * older SPA bundles; it is always null for active codes.
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
        // Newest first by the date the investor actually cares about: when the
        // deposit was approved (money in the wallet). Codes never expire, so
        // ordering by created_at buried an August credit made against a June
        // code below newer requests — same reason the admin list sorts on
        // confirmed_at. `id` breaks ties so pagination can't repeat/skip a row
        // when two deposits are approved in the same second.
        $deposits = DepositRequest::where('user_id', $request->user()->id)
            ->where('amount', '>', 0)
            ->orderByRaw('COALESCE(deposit_requests.confirmed_at, deposit_requests.created_at) desc')
            ->orderByDesc('id')
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
