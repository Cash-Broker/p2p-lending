<?php

namespace App\Filament\Widgets;

use App\Models\Loan;
use App\Models\PlatformMetric;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Loan-health-focused dashboard widget. Separate from `StatsOverview` per
 * approved discovery decision #5: a critical "late count" stat would be
 * visually buried in the general counters; a dedicated widget gives it
 * weight on the dashboard.
 *
 * Stats:
 *   active count
 *   late count + total funded amount of late loans
 *   defaulted count + total funded amount (always 0 in F1 — placeholder
 *     so the layout doesn't shift when F2 ships defaults automation)
 *   "Near default" placeholder for F2 (also shows 0 in F1)
 *   Last late-check timestamp + relative ("преди X мин/часа")
 */
class LoanHealthOverview extends BaseWidget
{
    /** Renders second on the dashboard, after the main StatsOverview. */
    protected static ?int $sort = 2;

    protected ?string $heading = 'Здраве на портфейла';

    protected function getStats(): array
    {
        $lateCount = Loan::where('status', Loan::STATUS_LATE)->count();
        $lateAmount = Loan::where('status', Loan::STATUS_LATE)->sum('funded_amount');
        $defaultCount = Loan::where('status', Loan::STATUS_DEFAULT)->count();
        $defaultAmount = Loan::where('status', Loan::STATUS_DEFAULT)->sum('funded_amount');

        $lastRunAt = PlatformMetric::measuredAt('last_late_check_run_at');
        $lastRunDescription = $lastRunAt
            ? $lastRunAt->diffForHumans()
            : 'никога';

        return [
            Stat::make('Закъснели кредити', $lateCount)
                ->description(number_format($lateAmount, 2, ',', ' ') . ' € общо финансирани')
                ->descriptionIcon('heroicon-o-banknotes')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($lateCount > 0 ? 'warning' : 'gray'),

            Stat::make('Просрочени кредити', $defaultCount)
                ->description(number_format($defaultAmount, 2, ',', ' ') . ' € общо финансирани')
                ->descriptionIcon('heroicon-o-banknotes')
                ->icon('heroicon-o-x-circle')
                ->color($defaultCount > 0 ? 'danger' : 'gray'),

            // F2 placeholder — surfaces in the UI now so ops know the slot exists
            // and the layout doesn't shift when the threshold is wired up.
            Stat::make('Близо до просрочване', 0)
                ->description('placeholder за Phase F2')
                ->descriptionIcon('heroicon-o-clock')
                ->icon('heroicon-o-bell-alert')
                ->color('gray'),

            Stat::make('Последна late-проверка', $lastRunDescription)
                ->description($lastRunAt?->format('d.m.Y H:i') ?? '—')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->icon('heroicon-o-shield-check')
                ->color($this->checkAgeColor($lastRunAt)),
        ];
    }

    /**
     * Mirrors the threshold logic in SchedulerHealthController so the
     * dashboard colour matches the /api/health/scheduler status.
     *
     * green   ≤ 26 h
     * orange  ≤ 48 h
     * red     > 48 h or never
     */
    private function checkAgeColor(?\Illuminate\Support\Carbon $lastRunAt): string
    {
        if ($lastRunAt === null) {
            return 'danger';
        }
        $minutes = $lastRunAt->diffInMinutes(now());
        if ($minutes > 48 * 60) {
            return 'danger';
        }
        if ($minutes > 26 * 60) {
            return 'warning';
        }
        return 'success';
    }
}
