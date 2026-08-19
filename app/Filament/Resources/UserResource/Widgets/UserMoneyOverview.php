<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Enums\PayoutType;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Wallet;
use App\Services\AccruedEarningsService;
use App\Services\PayoutLiabilityService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;

/**
 * «Паричен поток» — единият панел над списъка с потребители (Рени 2026-08-19:
 * «важно ми е да си следя паричните потоци»).
 *
 * Three headline figures and, underneath, where the accrued interest is being
 * earned — split by repayment plan with a share bar, so the answer to «как
 * вървят нещата» is one glance instead of three cards to add up.
 *
 * Custom view rather than the stock StatsOverviewWidget: the stock cards
 * stack into a wall of identical boxes and lose the hierarchy between «what
 * the platform holds» and «what it has earned for people» (Yordan 2026-08-19).
 *
 * Numbers, not decoration:
 *   • «Текущо начислени лихви» is the SUM of what each investor sees on their
 *     own dashboard as «Текуща печалба» — same service, so the admin view and
 *     the investor view can never drift apart (Reni's explicit requirement).
 *     It is interest EARNED TO DATE, never the remaining schedule.
 *   • The per-plan figures add up to that headline exactly.
 *   • Everything follows the table's own filter + search, so the panel always
 *     describes the rows underneath it.
 */
class UserMoneyOverview extends Widget
{
    use InteractsWithPageTable;

    protected string $view = 'filament.widgets.user-money-overview';

    protected int|string|array $columnSpan = 'full';

    /** Brand accents, one per plan — the eye tells them apart before reading. */
    private const PLAN_COLORS = [
        'amortizing' => '#1B2A4A',
        'interest_only' => '#22C55E',
        'capitalized' => '#F59E0B',
    ];

    protected function getTablePage(): string
    {
        return ListUsers::class;
    }

    protected function getViewData(): array
    {
        // reorder(): the table's ORDER BY is dead weight inside IN (...).
        $userIds = $this->getPageTableQuery()->reorder()->select('users.id');

        $wallets = Wallet::whereIn('user_id', $userIds)
            ->selectRaw('COALESCE(SUM(invested), 0) as invested')
            ->selectRaw('COALESCE(SUM(available), 0) as available')
            ->selectRaw('COALESCE(SUM(reserved), 0) as reserved')
            ->selectRaw('COALESCE(SUM(accrued), 0) as accrued')
            ->first();

        $accrued = app(AccruedEarningsService::class)->accruedByPlan($userIds);
        $capital = app(PayoutLiabilityService::class)->unpaidByPlan($userIds);

        return [
            'invested' => $this->money($wallets?->invested),
            'available' => $this->money($wallets?->available),
            'reserved' => $this->amount($wallets?->reserved),
            'inBalances' => $this->amount($wallets?->accrued),
            'reservedLabel' => $this->money($wallets?->reserved),
            'inBalancesLabel' => $this->money($wallets?->accrued),
            'interest' => $this->money($accrued['total']),
            'hasInterest' => bccomp($accrued['total'], '0', 2) > 0,
            'plans' => $this->plans($accrued),
            'capital' => $capital,
        ];
    }

    /**
     * One row per plan: label, its accrued interest, its share of the total
     * and the capital that is earning it.
     *
     * @param  array{total: string, by_plan: array<string, string>}  $accrued
     * @return array<int, array<string, mixed>>
     */
    private function plans(array $accrued): array
    {
        $total = $accrued['total'];
        $rows = [];

        foreach (PayoutType::cases() as $plan) {
            $interest = $accrued['by_plan'][$plan->value] ?? '0.00';

            $rows[] = [
                'key' => $plan->value,
                'label' => $plan->label(),
                'color' => self::PLAN_COLORS[$plan->value],
                'interest' => $this->money($interest),
                'raw' => $interest,
                // Display-only share; the euro figures above are the truth.
                'share' => bccomp($total, '0', 2) > 0
                    ? round(((float) $interest / (float) $total) * 100)
                    : 0,
            ];
        }

        return $rows;
    }

    private function amount(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    /** Display only — «104 150,00 €», like every other amount in the panel. */
    private function money(mixed $value): string
    {
        return Number::currency((float) ($value ?? 0), 'EUR', 'bg');
    }
}
