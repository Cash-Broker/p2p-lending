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

        // F2 — Buyback Queue pending: eligible + not dismissed + not executed.
        $buybackPendingCount = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('buyback_dismissed_at')
            ->whereNull('bought_back_at')
            ->count();
        $buybackPendingAmount = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('buyback_dismissed_at')
            ->whereNull('bought_back_at')
            ->sum('funded_amount');

        // F2 — bought-back this calendar month (count + total funded amount).
        $boughtBackMonthStart = now()->startOfMonth();
        $boughtBackMonthCount = Loan::where('status', Loan::STATUS_BOUGHT_BACK)
            ->where('bought_back_at', '>=', $boughtBackMonthStart)
            ->count();
        $boughtBackMonthAmount = Loan::where('status', Loan::STATUS_BOUGHT_BACK)
            ->where('bought_back_at', '>=', $boughtBackMonthStart)
            ->sum('funded_amount');

        $lateLastRunAt = PlatformMetric::measuredAt('last_late_check_run_at');
        $buybackLastRunAt = PlatformMetric::measuredAt('last_buyback_check_run_at');

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

            // F2 — replaces the F1 "Близо до просрочване" placeholder.
            Stat::make('Чакат buyback', $buybackPendingCount)
                ->description(number_format($buybackPendingAmount, 2, ',', ' ') . ' € в queue-a')
                ->descriptionIcon('heroicon-o-banknotes')
                ->icon('heroicon-o-bell-alert')
                ->color($buybackPendingCount > 0 ? 'warning' : 'gray'),

            // F2 — monthly buyback execution stat.
            Stat::make('Изкупени този месец', $boughtBackMonthCount)
                ->description(number_format($boughtBackMonthAmount, 2, ',', ' ') . ' € разпределени')
                ->descriptionIcon('heroicon-o-banknotes')
                ->icon('heroicon-o-check-badge')
                ->color($boughtBackMonthCount > 0 ? 'success' : 'gray'),

            Stat::make('Последна late-проверка', $lateLastRunAt
                    ? $lateLastRunAt->diffForHumans() : 'никога')
                ->description($lateLastRunAt?->format('d.m.Y H:i') ?? '—')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->icon('heroicon-o-shield-check')
                ->color($this->checkAgeColor($lateLastRunAt)),

            // F2 — buyback cron health stat, mirror of late-check above.
            Stat::make('Последна buyback-проверка', $buybackLastRunAt
                    ? $buybackLastRunAt->diffForHumans() : 'никога')
                ->description($buybackLastRunAt?->format('d.m.Y H:i') ?? '—')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->icon('heroicon-o-shield-check')
                ->color($this->checkAgeColor($buybackLastRunAt)),
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
