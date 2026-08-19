<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Services\AccruedEarningsService;
use App\Services\PayoutLiabilityService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * «Текущо начислени лихви», разбити по погасителен план (Йордан 2026-08-19:
 * «отдолу три по-малки прозореца, разделени по погасителни планове»).
 *
 * Same metric as the header card, so the three add up to it exactly — a
 * breakdown that does not reconcile with its own total is worse than none.
 * Under each figure sits the capital still working in that plan, which is the
 * context that makes the interest readable («колко пари карат тази лихва»).
 */
class InterestByPlanOverview extends BaseWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListUsers::class;
    }

    /** Three side by side under the header row; they stack on mobile. */
    protected function getColumns(): int
    {
        return 3;
    }

    /** Per-plan accent, so the eye tells them apart without reading. */
    private const PLAN_COLORS = [
        'amortizing' => 'info',
        'interest_only' => 'success',
        'capitalized' => 'warning',
    ];

    private const PLAN_ICONS = [
        'amortizing' => 'heroicon-m-calendar-days',
        'interest_only' => 'heroicon-m-receipt-percent',
        'capitalized' => 'heroicon-m-arrow-path-rounded-square',
    ];

    protected function getStats(): array
    {
        // reorder(): the table's ORDER BY is dead weight inside IN (...).
        $userIds = $this->getPageTableQuery()->reorder()->select('users.id');

        $accrued = app(AccruedEarningsService::class)->accruedByPlan($userIds)['by_plan'];
        $working = app(PayoutLiabilityService::class)->unpaidByPlan($userIds);

        return array_map(
            fn (PayoutType $plan) => $this->planStat(
                $plan,
                $accrued[$plan->value] ?? '0.00',
                $working[$plan->value]['principal'] ?? '0.00',
            ),
            // Same order as the offers on a loan: Анюитет · Само лихва · Капитализация.
            PayoutType::cases(),
        );
    }

    private function planStat(PayoutType $plan, string $interest, string $principal): Stat
    {
        $hasPositions = bccomp($principal, '0', 2) > 0;

        return Stat::make($plan->label(), static::money($interest))
            ->description($hasPositions
                ? static::money($principal).' в позиции'
                : 'няма активни позиции')
            ->descriptionIcon(self::PLAN_ICONS[$plan->value], 'before')
            ->color($hasPositions ? self::PLAN_COLORS[$plan->value] : 'gray');
    }

    /** Display only — formatted like every other amount in the panel. */
    protected static function money(mixed $amount): string
    {
        return Number::currency((float) ($amount ?? 0), 'EUR', 'bg');
    }
}
