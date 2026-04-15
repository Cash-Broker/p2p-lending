<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvestmentResource;
use App\Models\Investment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Investment::class);

        $investments = Investment::with(['loan.originator'])
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

        $investments = Investment::where('user_id', $userId)
            ->join('loans', 'investments.loan_id', '=', 'loans.id')
            ->select(
                DB::raw('SUM(investments.amount) as total_invested'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN loans.status IN ('active','funding','funded') THEN investments.amount ELSE 0 END) as active_amount"),
                DB::raw("SUM(CASE WHEN loans.status IN ('active','funding','funded') THEN 1 ELSE 0 END) as active_count"),
                DB::raw("SUM(CASE WHEN loans.status = 'late' THEN investments.amount ELSE 0 END) as late_amount"),
                DB::raw("SUM(CASE WHEN loans.status = 'default' THEN investments.amount ELSE 0 END) as default_amount"),
                DB::raw("SUM(CASE WHEN loans.status = 'repaid' THEN investments.amount ELSE 0 END) as repaid_amount"),
            )
            ->first();

        $totalEarned = Transaction::where('user_id', $userId)
            ->where('type', Transaction::TYPE_REPAYMENT_INTEREST)
            ->sum('amount');

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
            'active_investments_count' => (int) ($investments->active_count ?? 0),
            'breakdown_by_status' => [
                'active' => number_format((float) ($investments->active_amount ?? 0), 2, '.', ''),
                'late' => number_format((float) ($investments->late_amount ?? 0), 2, '.', ''),
                'default' => number_format((float) ($investments->default_amount ?? 0), 2, '.', ''),
                'repaid' => number_format((float) ($investments->repaid_amount ?? 0), 2, '.', ''),
            ],
            'breakdown_by_originator' => $byOriginator->map(fn ($o) => [
                'name' => $o->name,
                'amount' => number_format((float) $o->amount, 2, '.', ''),
            ]),
        ]);
    }
}
