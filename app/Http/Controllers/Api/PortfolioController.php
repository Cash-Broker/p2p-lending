<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestmentResource;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Investment::class);

        // withMax pulls the maximum days_late across the loan's late
        // schedules in a single sub-select per loan — zero N+1.
        // Surfaces as `loan.amortization_schedules_max_days_late` on the
        // loaded loan; the LoanResource picks it up as `days_overdue_max`.
        $investments = Investment::with([
            'loan' => fn ($q) => $q->withMax(
                ['amortizationSchedules as max_days_late_late_only' => fn ($s) => $s->where('status', 'late')],
                'days_late',
            ),
            'loan.originator',
            // Per-installment breakdown for the investor (offer positions).
            'schedules' => fn ($q) => $q->orderBy('due_date'),
        ])
            ->where('user_id', $request->user()->id)
            ->latest('invested_at')
            ->paginate(15);

        return response()->json([
            'data' => InvestmentResource::collection($investments),
            'meta' => [
                'current_page' => $investments->currentPage(),
                'last_page' => $investments->lastPage(),
                'per_page' => $investments->perPage(),
                'total' => $investments->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Investment::class);

        $userId = $request->user()->id;

        // Aggregate amounts AND counts per status so the UI can show both
        // "5 closed loans" and "€1,234 in late loans" without a second query.
        // The COUNT(DISTINCT loan_id) on late/default/bought_back avoids
        // double-counting when the investor has multiple investments in
        // the same loan.
        $investments = Investment::where('user_id', $userId)
            ->join('loans', 'investments.loan_id', '=', 'loans.id')
            ->select(
                DB::raw('SUM(investments.amount) as total_invested'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN loans.status IN ('active','funding','funded') THEN investments.amount ELSE 0 END) as active_amount"),
                DB::raw("SUM(CASE WHEN loans.status IN ('active','funding','funded') THEN 1 ELSE 0 END) as active_count"),
                DB::raw("SUM(CASE WHEN loans.status = 'late' THEN investments.amount ELSE 0 END) as late_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN loans.status = 'late' THEN loans.id END) as late_loans_count"),
                DB::raw("SUM(CASE WHEN loans.status = 'default' THEN investments.amount ELSE 0 END) as default_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN loans.status = 'default' THEN loans.id END) as default_loans_count"),
                // F2 — bought_back aggregate (terminal state per Q3). Shown
                // as a positive outcome in the investor portfolio UI.
                DB::raw("SUM(CASE WHEN loans.status = 'bought_back' THEN investments.amount ELSE 0 END) as bought_back_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN loans.status = 'bought_back' THEN loans.id END) as bought_back_loans_count"),
                DB::raw("SUM(CASE WHEN loans.status = 'repaid' THEN investments.amount ELSE 0 END) as repaid_amount"),
            )
            ->first();

        // Investor's "earned" is the wallet.earned bucket — the single source
        // of truth that already aggregates EVERY interest income path
        // (scheduled repayment, buyback, early repayment, and the new
        // scheduled-accrual release). Reading it directly avoids the bug of
        // re-summing a hand-picked subset of transaction types (which silently
        // dropped early-repayment + capitalized payout interest).
        $wallet = Wallet::where('user_id', $userId)->first();
        $totalEarned = $wallet?->earned ?? '0.00';

        // Breakdown by originator
        $byOriginator = Investment::where('investments.user_id', $userId)
            ->join('loans', 'investments.loan_id', '=', 'loans.id')
            ->join('originators', 'loans.originator_id', '=', 'originators.id')
            ->groupBy('originators.id', 'originators.name')
            ->select('originators.name', DB::raw('SUM(investments.amount) as amount'))
            ->get();

        return response()->json([
            'total_invested' => number_format((float) ($investments->total_invested ?? 0), 2, '.', ''),
            'total_earned' => number_format((float) $totalEarned, 2, '.', ''),
            // The three account figures the boss wants always visible.
            'invested' => number_format((float) ($wallet?->invested ?? 0), 2, '.', ''),       // Инвестирана сума
            'current_balance' => $wallet ? $wallet->currentBalance() : '0.00',                 // Текущо салдо
            'available' => number_format((float) ($wallet?->available ?? 0), 2, '.', ''),      // Свободни за теглене
            'active_investments_count' => (int) ($investments->active_count ?? 0),
            'breakdown_by_status' => [
                'active' => number_format((float) ($investments->active_amount ?? 0), 2, '.', ''),
                'late' => number_format((float) ($investments->late_amount ?? 0), 2, '.', ''),
                'default' => number_format((float) ($investments->default_amount ?? 0), 2, '.', ''),
                // F2 — bought_back breakdown (terminal, positive outcome).
                'bought_back' => number_format((float) ($investments->bought_back_amount ?? 0), 2, '.', ''),
                'repaid' => number_format((float) ($investments->repaid_amount ?? 0), 2, '.', ''),
            ],
            // Loan-level counts (DISTINCT loan_id) — UI uses these for the
            // "5 закъснели кредита" badge separately from the EUR amount.
            'late_loans_count' => (int) ($investments->late_loans_count ?? 0),
            'default_loans_count' => (int) ($investments->default_loans_count ?? 0),
            // F2 — bought-back loan count for the positive-outcome UI banner.
            'bought_back_loans_count' => (int) ($investments->bought_back_loans_count ?? 0),
            'breakdown_by_originator' => $byOriginator->map(fn ($o) => [
                'name' => $o->name,
                'amount' => number_format((float) $o->amount, 2, '.', ''),
            ]),
        ]);
    }
}
