<?php

namespace App\Services;

use App\Enums\PayoutType;
use App\Models\InvestmentSchedule;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * What the platform still owes its investors, split by repayment plan
 * (Йордан 2026-08-19: «да виждам как вървят нещата, какви пари ще трябва да
 * се плащат»).
 *
 * Read-only display math — it never touches wallets or the ledger. The figure
 * is derived from the UNPAID schedule rows, which is what makes it
 * self-maintaining: «като се изплатят някакви, ще трябва да се приспадат».
 * A row the payout engine marks `paid` drops out on the next render, and so
 * does one cancelled by an early closure (`closed`).
 *
 * Scope: offer-based investments. Legacy (pre-offer) positions are paid from
 * the loan-level amortization schedule and carry no per-investor rows — prod
 * has had zero live legacy loans since 2026-08-18 and none can be created, so
 * they are deliberately out. If that ever changes, this number would silently
 * under-report and needs the legacy branch added.
 */
class PayoutLiabilityService
{
    /** Rows that still have to be paid out. */
    private const UNPAID_STATUSES = [InvestmentSchedule::STATUS_PENDING, InvestmentSchedule::STATUS_LATE];

    /**
     * Outstanding principal + interest per payout plan.
     *
     * @param  EloquentBuilder|QueryBuilder|array<int, int>|null  $userIds  restrict to these investors (null = platform-wide)
     * @return array<string, array{principal: string, interest: string, total: string}>
     *                                                                                  keyed by PayoutType value, every plan always present
     */
    public function unpaidByPlan(EloquentBuilder|QueryBuilder|array|null $userIds = null): array
    {
        $rows = InvestmentSchedule::query()
            ->join('investments', 'investments.id', '=', 'investment_schedules.investment_id')
            ->whereIn('investment_schedules.status', self::UNPAID_STATUSES)
            ->whereNotNull('investments.loan_offer_id')
            ->when($userIds !== null, fn ($query) => $query->whereIn('investments.user_id', $userIds))
            ->groupBy('investments.payout_type')
            ->selectRaw('investments.payout_type as plan')
            ->selectRaw('COALESCE(SUM(investment_schedules.principal), 0) as principal')
            ->selectRaw('COALESCE(SUM(investment_schedules.interest), 0) as interest')
            ->get()
            ->keyBy('plan');

        $result = [];

        foreach (PayoutType::cases() as $plan) {
            $row = $rows->get($plan->value);
            $principal = $this->money($row?->principal);
            $interest = $this->money($row?->interest);

            $result[$plan->value] = [
                'principal' => $principal,
                'interest' => $interest,
                'total' => bcadd($principal, $interest, 2),
            ];
        }

        return $result;
    }

    /**
     * Interest still owed across every plan — the headline figure.
     *
     * @param  array<string, array{principal: string, interest: string, total: string}>  $byPlan
     */
    public function totalInterest(array $byPlan): string
    {
        return array_reduce(
            $byPlan,
            fn (string $carry, array $plan) => bcadd($carry, $plan['interest'], 2),
            '0.00',
        );
    }

    /** SUM() comes back as a float-ish string (or null on an empty set). */
    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }
}
