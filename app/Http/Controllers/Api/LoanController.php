<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvestRequest;
use App\Http\Resources\InvestmentResource;
use App\Http\Resources\LoanResource;
use App\Models\Favorite;
use App\Models\Loan;
use App\Services\InvestmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Loan::with(['originator', 'anonymizedProfile'])
            ->whereIn('status', Loan::FUNDABLE_STATUSES);

        // Filters
        if ($request->filled('type')) {
            $query->whereIn('type', (array) $request->type);
        }
        if ($request->filled('originator_id')) {
            $query->whereIn('originator_id', (array) $request->originator_id);
        }
        if ($request->filled('amount_min')) {
            $query->where('amount', '>=', $request->amount_min);
        }
        if ($request->filled('amount_max')) {
            $query->where('amount', '<=', $request->amount_max);
        }
        if ($request->filled('interest_rate_min')) {
            $query->where('interest_rate', '>=', $request->interest_rate_min);
        }
        if ($request->filled('interest_rate_max')) {
            $query->where('interest_rate', '<=', $request->interest_rate_max);
        }
        if ($request->filled('term_min')) {
            $query->where('term_months', '>=', $request->term_min);
        }
        if ($request->filled('term_max')) {
            $query->where('term_months', '<=', $request->term_max);
        }
        if ($request->filled('risk_class')) {
            $query->whereHas('anonymizedProfile', function ($q) use ($request) {
                $q->whereIn('risk_class', (array) $request->risk_class);
            });
        }

        // Sorting
        $query->when($request->sort, function ($q, $sort) {
            return match ($sort) {
                'highest_rate' => $q->orderByDesc('interest_rate'),
                'shortest_term' => $q->orderBy('term_months'),
                'most_funded' => $q->orderByRaw('funded_amount / amount DESC'),
                default => $q->latest('published_at'), // newest
            };
        }, fn ($q) => $q->latest('published_at'));

        $loans = $query->paginate(12);

        return response()->json([
            'data' => LoanResource::collection($loans),
            'meta' => [
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
            ],
        ]);
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->load(['originator', 'anonymizedProfile', 'amortizationSchedules'])
            ->loadCount('investments');

        return response()->json(new LoanResource($loan));
    }

    public function invest(InvestRequest $request, Loan $loan, InvestmentService $service): JsonResponse
    {
        $investment = $service->invest(
            $request->user(),
            $loan,
            number_format((float) $request->amount, 2, '.', '')
        );

        return response()->json([
            'message' => 'Investment successful.',
            'investment' => new InvestmentResource($investment->load('loan')),
        ], 201);
    }

    public function toggleFavorite(Request $request, Loan $loan): JsonResponse
    {
        $existing = Favorite::where('user_id', $request->user()->id)
            ->where('loan_id', $loan->id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['favorited' => false]);
        }

        Favorite::create([
            'user_id' => $request->user()->id,
            'loan_id' => $loan->id,
        ]);

        return response()->json(['favorited' => true], 201);
    }

    public function favorites(Request $request): JsonResponse
    {
        $loans = Loan::with(['originator', 'anonymizedProfile'])
            ->whereHas('favorites', fn ($q) => $q->where('user_id', $request->user()->id))
            ->paginate(12);

        return response()->json([
            'data' => LoanResource::collection($loans),
            'meta' => [
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
            ],
        ]);
    }
}
