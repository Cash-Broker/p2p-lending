<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Services\PayoutLiabilityService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The «Лихви за плащане» figure above, broken down by repayment plan
 * (Йордан 2026-08-19: «отдолу три по-малки прозореца, разделени по
 * погасителни планове»).
 *
 * Every card shows the INTEREST as its headline so the three add up to the
 * total above them exactly — a breakdown that does not reconcile with its own
 * header is worse than no breakdown. The principal each plan still owes is
 * disclosed underneath instead: for «Анюитет» it rides in the same
 * installments («еди колко си главница, еди колко си лихва и сумарно
 * толкова»), for the other two it comes back at maturity.
 *
 * Same filtered user set as the table and the header cards — all three
 * describe the same rows.
 */
class InterestByPlanOverview extends BaseWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListUsers::class;
    }

    /** Three side by side, under the two-wide header row. */
    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        // reorder(): the table's ORDER BY is dead weight inside IN (...).
        $userIds = $this->getPageTableQuery()->reorder()->select('users.id');

        $byPlan = app(PayoutLiabilityService::class)->unpaidByPlan($userIds);

        return array_map(
            fn (PayoutType $plan) => $this->planStat($plan, $byPlan[$plan->value]),
            // Same order as the offers on a loan: Анюитет · Само лихва · Капитализация.
            PayoutType::cases(),
        );
    }

    /**
     * @param  array{principal: string, interest: string, total: string}  $figures
     */
    private function planStat(PayoutType $plan, array $figures): Stat
    {
        return Stat::make($plan->label(), static::money($figures['interest']))
            ->description($this->principalNote($plan, $figures))
            ->color('gray');
    }

    /**
     * @param  array{principal: string, interest: string, total: string}  $figures
     */
    private function principalNote(PayoutType $plan, array $figures): string
    {
        if (bccomp($figures['principal'], '0', 2) <= 0) {
            return 'няма активни позиции';
        }

        $principal = static::money($figures['principal']);
        $total = static::money($figures['total']);

        return $plan === PayoutType::Amortizing
            // Amortizing pays both in the same installment, so the sum is the
            // number that actually leaves the platform each month.
            ? "главница {$principal} · общо {$total}"
            : "+ главница {$principal} на падеж";
    }

    /** Display only — formatted like every other amount in the panel. */
    protected static function money(mixed $amount): string
    {
        return Number::currency((float) ($amount ?? 0), 'EUR', 'bg');
    }
}
