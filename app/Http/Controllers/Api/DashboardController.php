<?php

namespace App\Http\Controllers\Api;

use App\Enums\PayoutType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\LoanOffer;
use App\Models\LoanPromotion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccruedEarningsService;
use App\Services\BonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request, AccruedEarningsService $accruedEarnings, BonusService $bonusService): JsonResponse
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

        $latestLoans = Loan::with([
            'originator',
            'anonymizedProfile',
            // Offers feed offer_rate_range — the loan-level rate is nullable
            // since 2026-08-10, so the range is the primary display.
            'offers' => fn ($q) => $q->where('is_enabled', true)->orderBy('position'),
        ])
            ->whereIn('status', Loan::FUNDABLE_STATUSES)
            // Like the marketplace board, the dashboard discovery feed never
            // shows private (link-only) loans — those are reachable only via
            // their share link / grant.
            ->where('visibility', Loan::VISIBILITY_PUBLIC)
            ->latest('published_at')
            ->limit(5)
            ->get();

        $monthlyEarnings = $this->getMonthlyEarnings($user->id);

        // Lifetime figures behind the «Спечелени» / «Изтеглени» buttons
        // (Reni 2026-08-13). Withdrawn = net paid-out withdrawals; the fee
        // rows are a separate type and deliberately not part of the figure.
        $withdrawnTotal = (string) Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_WITHDRAWAL)
            ->sum('amount');

        // ── Engagement pack (2026-08-14): every block below is display-only
        //    honest math — no wallet/ledger involvement anywhere. ──

        // «Докато те нямаше…»: deltas since the PREVIOUS dashboard visit.
        // Read first, then stamp the new visit. Base-query update ON PURPOSE:
        // a model save() would fire the Auditable trait (an immutable
        // audit_logs row per page view — forensic noise that can never be
        // purged) and rewrite users.updated_at on every visit.
        $previousSeen = $user->dashboard_seen_at;
        User::whereKey($user->id)->toBase()->update(['dashboard_seen_at' => now()]);
        $this->recordVisit($user->id, $previousSeen);

        $accrual = $accruedEarnings->forUser($user->id);
        $sinceLastVisit = $this->sinceLastVisit($user, $previousSeen, $accrual, $accruedEarnings);

        return response()->json([
            'wallet' => new WalletResource($wallet),
            // What the investor still has to do to unlock a granted bonus —
            // null when there is nothing locked (Reni 2026-08-18).
            'locked_bonus' => $bonusService->lockedSummary($user->id),
            'active_investments_count' => $activeInvestmentsCount,
            'recent_transactions' => TransactionResource::collection($recentTransactions),
            'latest_loans' => LoanResource::collection($latestLoans),
            'monthly_earnings' => $monthlyEarnings,
            // «Текуща печалба» (2026-08-13): schedule-accrued interest not yet
            // paid out — display-only reference, NOT withdrawable money. Always
            // live (per-second) + daily/hourly paces; no admin variant switch.
            'earned_accrual' => $accrual,
            'lifetime_totals' => [
                'earned_paid' => (string) $wallet->earned,
                'withdrawn_total' => bcadd($withdrawnTotal ?: '0', '0', 2),
            ],
            'since_last_visit' => $sinceLastVisit,
            'next_payout' => $this->nextPayout($user->id),
            'working_days' => $this->workingDays($user->id),
            // Legacy single range (IdleMoneyStrip) is the interest-only slice
            // of the per-plan ranges — one query feeds both keys.
            'market_rate_range' => ($marketRateRanges = $this->marketRateRanges())[PayoutType::InterestOnly->value],
            'market_rate_ranges' => $marketRateRanges,
        ]);
    }

    /**
     * Admin-only visit analytics (2026-08-15): a new ENTRY is the first load
     * ever or a load 30+ minutes after the previous one — refreshes inside a
     * session don't count. One counter row per Sofia calendar day; failures
     * are swallowed (analytics must never break the dashboard).
     */
    private function recordVisit(int $userId, ?Carbon $previousSeen): void
    {
        if ($previousSeen !== null && $previousSeen->gt(now()->subMinutes(30))) {
            return;
        }

        try {
            DB::insert(
                'INSERT INTO user_visit_days (user_id, visit_date, entries, created_at, updated_at)
                 VALUES (?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE entries = entries + 1, updated_at = NOW()',
                [$userId, now()->timezone('Europe/Sofia')->toDateString()],
            );
        } catch (\Throwable $e) {
            Log::warning('Visit tracking failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * «Докато те нямаше…» — what happened between the previous dashboard
     * visit and now. Computed on every visit after the first; the SPA keeps
     * one banner per tab session.
     *
     * @return array<string, mixed>|null
     */
    private function sinceLastVisit(User $user, ?Carbon $previousSeen, array $accrualNow, AccruedEarningsService $accruedEarnings): ?array
    {
        // NO minimum gap (Reni 2026-08-15: «сега влязох и никъде го няма») —
        // the block is computed on EVERY visit after the first; the SPA keeps
        // one banner per tab session (sessionStorage) so it greets every new
        // entry without duplicating inside a session.
        if ($previousSeen === null) {
            return null;
        }

        // Income received in the window — exact ledger truth: interest income
        // paths + promo/admin bonuses.
        $received = (string) Transaction::where('user_id', $user->id)
            ->whereIn('type', [
                Transaction::TYPE_REPAYMENT_INTEREST,
                Transaction::TYPE_BUYBACK_INTEREST,
                Transaction::TYPE_EARLY_REPAYMENT_INTEREST,
                Transaction::TYPE_INTEREST_RELEASED,
                Transaction::TYPE_BONUS,
            ])
            ->where('created_at', '>', $previousSeen)
            ->sum('amount');
        $received = bcadd($received ?: '0', '0', 2);

        // Flash promos that started while they were away and still run.
        $newPromos = LoanPromotion::query()
            ->running()
            ->where('starts_at', '>', $previousSeen)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::FUNDABLE_STATUSES)
                ->where('visibility', Loan::VISIBILITY_PUBLIC))
            ->count();

        // Public loans about to fill — «ако не побързаш, ще го изпуснеш».
        // SQL pre-filter keeps the fundingCap() hydration to the handful of
        // near-full candidates instead of every open loan on the platform.
        $hotLoan = Loan::query()
            ->whereIn('status', Loan::FUNDABLE_STATUSES)
            ->where('visibility', Loan::VISIBILITY_PUBLIC)
            ->whereRaw('funded_amount >= 0.85 * COALESCE(investable_amount, amount)')
            ->limit(10)
            ->get()
            ->map(function (Loan $loan) {
                $cap = (float) $loan->fundingCap();
                $free = bcsub($loan->fundingCap(), (string) $loan->funded_amount, 2);
                $pct = $cap > 0 ? (int) round(((float) $loan->funded_amount / $cap) * 100) : 0;

                return ['id' => $loan->id, 'type' => $loan->type, 'funded_percentage' => $pct, 'free' => $free];
            })
            ->filter(fn (array $l) => $l['funded_percentage'] >= 85 && bccomp($l['free'], '0', 2) > 0)
            ->sortByDesc('funded_percentage')
            ->first();

        // «Печалбата ти порасна с +X €» (Reni 2026-08-14): monthly payouts are
        // rare, but the running profit ticks for anyone with deployed money —
        // that growth IS the news. Shown only when NO payout landed in the
        // window: then the currently-unpaid rows are exactly the rows that
        // were accruing at the previous visit too, so live(now) − live(prev)
        // is the exact counter delta. With a payout in the window the paid
        // amount itself is the (bigger) headline and the delta would be
        // ill-defined — «received» carries the banner instead.
        $accrualGrowth = '0.00';
        if (bccomp($received, '0', 2) <= 0) {
            $accrualPrev = $accruedEarnings->forUser($user->id, $previousSeen);
            $growth = bcsub($accrualNow['amount_live'], $accrualPrev['amount_live'], 2);
            $accrualGrowth = bccomp($growth, '0', 2) > 0 ? $growth : '0.00';
        }

        // REAL news only (Reni 2026-08-15, round 3: «нещо не го уцелваме» —
        // a «докато те нямаше» against a 3-minute-old visit with a static
        // fact as the headline reads broken). No news → no banner; the idle-
        // money nudge lives as its own permanent strip on the dashboard.
        $hasNews = bccomp($received, '0', 2) > 0
            || bccomp($accrualGrowth, '0', 2) > 0
            || $newPromos > 0
            || $hotLoan !== null;

        if (! $hasNews) {
            return null;
        }

        return [
            'previous_seen_at' => $previousSeen->toIso8601String(),
            'received' => $received,
            'accrual_growth' => $accrualGrowth,
            'new_promos' => $newPromos,
            'hot_loan' => $hotLoan,
        ];
    }

    /**
     * The investor's NEXT scheduled payout across all offer investments in
     * payout-eligible loans: the nearest unpaid due date + everything it pays
     * that day. Display-only anticipation hook («след 12 дни · +13,33 €»).
     *
     * @return array<string, mixed>|null
     */
    private function nextPayout(int $userId): ?array
    {
        // NO lower date bound on purpose: an overdue-unpaid installment
        // (manual-mode loan waiting for the admin button) must show as «днес»,
        // not silently vanish from the promise (review 2026-08-14).
        $nextDue = InvestmentSchedule::query()
            ->whereIn('status', ['pending', 'late'])
            ->whereHas('investment', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES))
            ->orderBy('due_date')
            ->value('due_date');

        if ($nextDue === null) {
            return null;
        }

        $dueDate = Carbon::parse($nextDue)->startOfDay();

        $rows = InvestmentSchedule::query()
            ->whereIn('status', ['pending', 'late'])
            ->whereDate('due_date', $dueDate->toDateString())
            ->whereHas('investment', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES))
            ->get(['principal', 'interest']);

        $amount = $rows->reduce(
            fn (string $carry, $row) => bcadd($carry, bcadd((string) $row->principal, (string) $row->interest, 2), 2),
            '0.00',
        );

        $daysLeft = max(0, (int) floor(now()->startOfDay()->diffInDays($dueDate, false)));

        // Presentation-only ring fill: the standard 30-day cycle for monthly
        // plans; far-out payouts (capitalized maturities) keep a small anchor
        // fill instead of a dead-empty ring.
        $progress = $daysLeft >= 30 ? 0.08 : round((30 - $daysLeft) / 30, 4);

        return [
            'due_date' => $dueDate->toDateString(),
            'amount' => $amount,
            'days_left' => $daysLeft,
            'period_progress' => max(0.0, min(1.0, $progress)),
        ];
    }

    /**
     * «Парите ти работят от N дни» — days since the OLDEST investment still
     * living in a payout-eligible loan. Honest streak: it is literally how
     * long that money has been earning. Null when nothing is deployed.
     */
    private function workingDays(int $userId): ?int
    {
        $oldest = Investment::query()
            ->where('user_id', $userId)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::PAYOUT_ELIGIBLE_STATUSES))
            ->min('invested_at');

        if ($oldest === null) {
            return null;
        }

        // CALENDAR days in Europe/Sofia (Reni 2026-08-15): the counter flips
        // at Bulgarian midnight — a rolling 24h count kept showing «4 дни»
        // through the next morning because it incremented at the invest hour.
        $investedDay = Carbon::parse($oldest)->timezone('Europe/Sofia')->startOfDay();
        $today = now()->timezone('Europe/Sofia')->startOfDay();

        return max(1, (int) $investedDay->diffInDays($today));
    }

    /**
     * Live min/max annual rate per payout structure across enabled offers on
     * fundable loans — feeds the what-if picker's honest projections. Private
     * loans deliberately included (Yordan 2026-08-14: the platform currently
     * sells by private link only, and the card must not die) — only the bare
     * percentage flows out, no loan identity/amount/count. A structure with no
     * live offers falls back to its seeded default so the dream never goes dark.
     *
     * @return array<string, array{min: string, max: string}> keyed by PayoutType value
     */
    private function marketRateRanges(): array
    {
        $byType = LoanOffer::query()
            ->where('is_enabled', true)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::FUNDABLE_STATUSES))
            ->get(['payout_type', 'interest_rate'])
            ->groupBy(fn (LoanOffer $offer) => $offer->payout_type->value);

        $ranges = [];
        foreach (PayoutType::cases() as $type) {
            $rates = $byType->get($type->value, collect())
                ->pluck('interest_rate')
                ->map(fn ($r) => (string) $r);

            $ranges[$type->value] = $rates->isEmpty()
                ? ['min' => $type->defaultRate(), 'max' => $type->defaultRate()]
                : ['min' => $rates->min(), 'max' => $rates->max()];
        }

        return $ranges;
    }

    // Aggregate principal + interest income by month for chart data.
    private function getMonthlyEarnings(int $userId): Collection
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
