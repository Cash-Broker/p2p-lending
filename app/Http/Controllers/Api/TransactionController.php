<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionFilterRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function index(TransactionFilterRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $query = Transaction::where('user_id', $request->user()->id);

        if ($request->filled('type')) {
            $query->whereIn('type', (array) $request->type);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // `id desc` breaks ties on created_at, which are the rule rather than
        // the exception here: one repayment writes principal + interest in the
        // same transaction, the payout engine writes a whole batch. Without
        // it, LIMIT/OFFSET could show the investor the same row on two pages
        // and hide another one entirely.
        $transactions = $query->latest('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => TransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
