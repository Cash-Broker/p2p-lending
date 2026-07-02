<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Investment::class);

        $user = $request->user();
        $wallet = $user->wallet;

        $activeInvestmentsCount = $user->investments()
            ->whereHas('loan', fn ($q) => $q->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_FUNDING, Loan::STATUS_FUNDED]))
            ->count();

        $recentTransactions = $user->transactions()
            ->latest('created_at')
            ->limit(5)
            ->get();

        $latestLoans = Loan::with(['originator', 'anonymizedProfile'])
            ->whereIn('status', Loan::FUNDABLE_STATUSES)
            // Like the marketplace board, the dashboard discovery feed never
            // shows private (link-only) loans — those are reachable only via
            // their share link / grant.
            ->where('visibility', Loan::VISIBILITY_PUBLIC)
            ->latest('published_at')
            ->limit(5)
            ->get();

        $monthlyEarnings = $this->getMonthlyEarnings($user->id);

        return response()->json([
            'wallet' => new WalletResource($wallet),
            'active_investments_count' => $activeInvestmentsCount,
            'recent_transactions' => TransactionResource::collection($recentTransactions),
            'latest_loans' => LoanResource::collection($latestLoans),
            'monthly_earnings' => $monthlyEarnings,
        ]);
    }

    // Aggregate principal + interest income by month for chart data.
    private function getMonthlyEarnings(int $userId): \Illuminate\Support\Collection
    {
        $sixMonthsAgo = Carbon::now()->subMonths(6)->startOfMonth();

        // Every path that actually pays the investor counts: scheduled
        // repayments, buyback (originator), early repayment, and the release
        // of locked capitalized interest. `interest_accrued` is deliberately
        // excluded — it is locked recognition only; counting it AND its later
        // release would double-count. Same income definition as wallet.earned
        // (see PortfolioController::summary).
        $principalTypes = [
            Transaction::TYPE_REPAYMENT_PRINCIPAL,
            Transaction::TYPE_BUYBACK_PRINCIPAL,
            Transaction::TYPE_EARLY_REPAYMENT_PRINCIPAL,
        ];
        $interestTypes = [
            Transaction::TYPE_REPAYMENT_INTEREST,
            Transaction::TYPE_BUYBACK_INTEREST,
            Transaction::TYPE_EARLY_REPAYMENT_INTEREST,
            Transaction::TYPE_INTEREST_RELEASED,
        ];

        $principalIn = implode(',', array_fill(0, count($principalTypes), '?'));
        $interestIn = implode(',', array_fill(0, count($interestTypes), '?'));

        $rawEarnings = Transaction::where('user_id', $userId)
            ->whereIn('type', array_merge($principalTypes, $interestTypes))
            ->where('created_at', '>=', $sixMonthsAgo)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(CASE WHEN type IN ($principalIn) THEN amount ELSE 0 END) as principal,
                SUM(CASE WHEN type IN ($interestIn) THEN amount ELSE 0 END) as interest",
                array_merge($principalTypes, $interestTypes))
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        // Fill missing months with zeros so chart always has 6 data points
        $months = collect();
        for ($i = 5; $i >= 0; $i--) {
            $key = Carbon::now()->subMonths($i)->format('Y-m');
            $found = $rawEarnings->firstWhere('month', $key);
            $months->push([
                'month' => $key,
                'principal' => $found ? $found->principal : '0.00',
                'interest' => $found ? $found->interest : '0.00',
            ]);
        }

        return $months;
    }
}
