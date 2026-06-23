<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvestRequest;
use App\Http\Requests\LoanFilterRequest;
use App\Http\Resources\InvestmentResource;
use App\Http\Resources\LoanEventResource;
use App\Http\Resources\LoanResource;
use App\Models\Favorite;
use App\Models\Loan;
use App\Services\InvestmentService;
use App\Services\OfferProjectionService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function index(LoanFilterRequest $request): JsonResponse
    {
        $query = Loan::with([
            'originator',
            'anonymizedProfile',
            'offers' => fn ($q) => $q->where('is_enabled', true)->orderBy('position'),
        ])
            ->whereIn('status', Loan::FUNDABLE_STATUSES)
            // The public board never shows private (link-only) loans.
            ->where('visibility', Loan::VISIBILITY_PUBLIC);

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
                'most_funded' => $q->orderByRaw('funded_amount / COALESCE(investable_amount, amount) DESC'),
                default => $q->latest('published_at'),
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

    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('view', $loan);

        $loan->load([
            'originator',
            'anonymizedProfile',
            'coBorrowerAnonymizedProfile',
            'amortizationSchedules',
            'offers' => fn ($q) => $q->where('is_enabled', true)->orderBy('position'),
        ])->loadCount('investments');

        return response()->json(new LoanResource($loan));
    }

    /**
     * Per-offer profit projection for a chosen amount — the side-by-side
     * comparison the client uses to pick a structure. Returns, for each enabled
     * offer: total interest (profit), total repaid, the recurring/maturity
     * payment, and the full projected schedule. Pure projection — persists
     * nothing.
     */
    public function offerQuotes(Request $request, Loan $loan, OfferProjectionService $projection): JsonResponse
    {
        $this->authorize('view', $loan);

        // Default to the full investable amount; clamp junk input to it.
        // bcmath-safe — no float cast on the query param.
        try {
            $amount = Money::normalizePositive($request->query('amount', $loan->investableAmount()));
        } catch (\InvalidArgumentException) {
            $amount = $loan->investableAmount();
        }
        $term = (int) $loan->term_months;

        $quotes = $loan->offers()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->get()
            ->map(function ($offer) use ($projection, $amount, $term) {
                $summary = $projection->summary($amount, (string) $offer->interest_rate, $term, $offer->payout_type);
                $schedule = $projection->schedule($amount, (string) $offer->interest_rate, $term, $offer->payout_type);

                return [
                    'loan_offer_id' => $offer->id,
                    'payout_type' => $offer->payout_type->value,
                    'label' => $offer->payout_type->label(),
                    'description' => $offer->payout_type->description(),
                    'interest_rate' => (string) $offer->interest_rate,
                    'amount' => $amount,
                    'total_interest' => $summary['total_interest'],
                    'total_repaid' => $summary['total_repaid'],
                    'monthly_payment' => $summary['monthly_payment'],
                    'maturity_payment' => $summary['maturity_payment'],
                    'schedule' => array_map(fn ($row) => [
                        'due_date' => $row['due_date']->toDateString(),
                        'principal' => $row['principal'],
                        'interest' => $row['interest'],
                        'total' => $row['total'],
                    ], $schedule),
                ];
            });

        return response()->json([
            'data' => $quotes,
            'amount' => $amount,
            'term_months' => $term,
        ]);
    }

    /**
     * Resolve a private loan's share link: find the loan by token, grant the
     * authenticated investor persistent access (so subsequent show/invest
     * calls — which carry no token — pass LoanPolicy::view), and return the
     * loan id for the SPA to navigate to. Only PRIVATE loans resolve here;
     * a bad/expired/public token is a 404.
     */
    public function shared(Request $request, string $token): JsonResponse
    {
        $loan = Loan::where('share_token', $token)
            ->where('visibility', Loan::VISIBILITY_PRIVATE)
            ->first();

        if ($loan === null) {
            return response()->json(['message' => 'Линкът е невалиден или вече не е активен.'], 404);
        }

        // The link only opens once the loan is actually available to investors
        // (published+). A draft private loan would otherwise grant access and
        // then 403 on the detail page — surfacing as a vague "load error".
        if (! in_array($loan->status, Loan::INVESTOR_VISIBLE_STATUSES, true)) {
            return response()->json(['message' => 'Кредитът все още не е наличен. Моля, опитайте по-късно.'], 404);
        }

        $loan->grantAccessTo($request->user());

        return response()->json(['loan_id' => $loan->id]);
    }

    public function invest(InvestRequest $request, Loan $loan, InvestmentService $service): JsonResponse
    {
        // Enforce access — private loans require a position or a link grant.
        // Harmless for public loans (LoanPolicy::view returns true).
        $this->authorize('view', $loan);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['message' => 'X-Idempotency-Key header is required.'], 422);
        }

        try {
            // bcmath-safe ingress — never float-cast user input.
            $amount = Money::normalizePositive($request->amount);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $investment = $service->invest(
            $request->user(),
            $loan,
            $amount,
            $idempotencyKey,
            (int) $request->loan_offer_id,
        );

        return response()->json([
            'message' => 'Investment successful.',
            'investment' => new InvestmentResource($investment->load('loan')),
        ], 201);
    }

    public function toggleFavorite(Request $request, Loan $loan): JsonResponse
    {
        // Can't favorite a loan you can't access (e.g. a private loan you were
        // never sent the link to).
        $this->authorize('view', $loan);

        // Delete-first pattern avoids TOCTOU race condition
        $deleted = Favorite::where('user_id', $request->user()->id)
            ->where('loan_id', $loan->id)
            ->delete();

        if ($deleted > 0) {
            return response()->json(['favorited' => false]);
        }

        try {
            Favorite::create([
                'user_id' => $request->user()->id,
                'loan_id' => $loan->id,
            ]);

            return response()->json(['favorited' => true], 201);
        } catch (UniqueConstraintViolationException) {
            // Concurrent request already created the favorite
            return response()->json(['favorited' => true]);
        }
    }

    public function favorites(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $loans = Loan::with([
            'originator',
            'anonymizedProfile',
            'offers' => fn ($q) => $q->where('is_enabled', true)->orderBy('position'),
        ])
            ->whereHas('favorites', fn ($q) => $q->where('user_id', $userId))
            // Never surface a private loan the user no longer has access to.
            ->where(function ($q) use ($userId) {
                $q->where('visibility', Loan::VISIBILITY_PUBLIC)
                    ->orWhereHas('grants', fn ($g) => $g->where('user_id', $userId))
                    ->orWhereHas('investments', fn ($i) => $i->where('user_id', $userId));
            })
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

    /**
     * Investor-facing loan-event timeline. Returns paginated, anonymised
     * lifecycle events for a loan the user has at least one investment in.
     *
     * Authorization: LoanPolicy::viewEvents — admin sees all loans;
     * investor only sees events for loans they hold/held a position in.
     *
     * Sanitisation: LoanEventResource enforces a strict whitelist on
     * metadata keys (see resource class). triggered_by_user_id never
     * leaves the server.
     */
    public function events(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('viewEvents', $loan);

        $events = $loan->events()
            ->latest('occurred_at')
            ->paginate(20);

        return response()->json([
            'data' => LoanEventResource::collection($events),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }
}
