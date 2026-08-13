<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\LoanPromotion;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BonusCreditedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Flash promo engine (Reni 2026-08-14). Two jobs:
 *
 *   1. grantInvestBonus() — called INSIDE InvestmentService::invest()'s
 *      transaction right after the investment is created: if the loan has a
 *      running promotion, pay the investor the upfront bonus immediately
 *      (WalletService::bonus → TYPE_BONUS → `available`), atomically with
 *      the investment. Budget cap is enforced under a row lock on the
 *      promotion; the DB CHECK (bonus_paid_total ≤ budget_cap) is the
 *      backstop. Reference `promo:{id}:investment:{iid}` is unique per
 *      investment — a replayed idempotency key never reaches this code
 *      (invest() returns the existing investment first), and the reference
 *      guard below closes the door on any other double-grant path.
 *
 *   2. activeForDisplay() — the dashboard panel feed: running promotions
 *      whose loan is still investable (fundable status + free capacity),
 *      soonest-ending first («офертата валидна за 60 мин» — urgency sells).
 *
 * Money first, notifications after commit: the investor's bonus bell/mail
 * rides DB::afterCommit and can never roll back the money.
 */
class PromotionService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Pay the upfront promo bonus for a fresh investment, if its loan has a
     * running promotion with budget left. Returns the granted amount or null.
     * MUST run inside the invest transaction.
     */
    public function grantInvestBonus(Investment $investment, Loan $loan, User $user): ?string
    {
        $promotion = LoanPromotion::query()
            ->where('loan_id', $loan->id)
            ->running()
            ->lockForUpdate()
            ->orderBy('id')
            ->first();

        if (! $promotion) {
            return null;
        }

        // Belt-and-braces: one bonus per investment, ever.
        $reference = "promo:{$promotion->id}:investment:{$investment->id}";
        if (Transaction::where('reference', $reference)->where('type', Transaction::TYPE_BONUS)->exists()) {
            return null;
        }

        // bonus = amount × percent / 100, truncated to 2dp (bcmath, no floats).
        $bonus = bcdiv(bcmul((string) $investment->amount, (string) $promotion->bonus_percent, 4), '100', 2);

        // Budget cap: pay at most what is left; zero left → no grant.
        $remaining = $promotion->remainingBudget();
        if ($remaining !== null && bccomp($bonus, $remaining, 2) > 0) {
            $bonus = $remaining;
        }

        if (bccomp($bonus, '0', 2) <= 0) {
            return null;
        }

        $this->walletService->bonus(
            $user->id,
            $bonus,
            "Промо бонус {$promotion->bonus_percent}% за инвестиция в кредит #{$loan->id}",
            $reference,
        );

        $promotion->forceFill([
            'bonus_paid_total' => bcadd((string) $promotion->bonus_paid_total, $bonus, 2),
        ])->save();

        // Bell + mail AFTER the money commits; failure only logs.
        DB::afterCommit(function () use ($user, $bonus, $loan) {
            try {
                $user->notify(new BonusCreditedNotification($bonus, "Промо оферта — кредит #{$loan->id}"));
            } catch (\Throwable $e) {
                Log::error('Promo bonus notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        });

        return $bonus;
    }

    /**
     * Running promotions whose loan can still take money — the dashboard
     * panel feed. Soonest-ending first, capped at 3.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activeForDisplay(): Collection
    {
        return LoanPromotion::query()
            ->running()
            // PUBLIC loans only — a promo on a private (link-only) loan would
            // broadcast its existence to every investor, defeating the link.
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::FUNDABLE_STATUSES)
                ->where('visibility', Loan::VISIBILITY_PUBLIC))
            ->with([
                'loan.offers' => fn ($q) => $q->where('is_enabled', true)->orderBy('position'),
            ])
            ->orderBy('ends_at')
            ->get()
            ->filter(function (LoanPromotion $promotion) {
                $free = bcsub($promotion->loan->fundingCap(), (string) $promotion->loan->funded_amount, 2);
                $budget = $promotion->remainingBudget();

                return bccomp($free, '0', 2) > 0 && ($budget === null || bccomp($budget, '0', 2) > 0);
            })
            ->take(3)
            ->values()
            ->map(function (LoanPromotion $promotion) {
                $rates = $promotion->loan->offers->pluck('interest_rate')->map(fn ($r) => (string) $r);

                return [
                    'id' => $promotion->id,
                    'bonus_percent' => (string) $promotion->bonus_percent,
                    'starts_at' => $promotion->starts_at->toIso8601String(),
                    'ends_at' => $promotion->ends_at->toIso8601String(),
                    'loan' => [
                        'id' => $promotion->loan->id,
                        'type' => $promotion->loan->type,
                        'term_months' => (int) $promotion->loan->term_months,
                        'offer_rate_range' => $rates->isEmpty() ? null : [$rates->min(), $rates->max()],
                        'free_capacity' => bcsub($promotion->loan->fundingCap(), (string) $promotion->loan->funded_amount, 2),
                    ],
                ];
            });
    }
}
