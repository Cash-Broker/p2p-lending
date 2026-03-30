<?php

namespace App\Filament\Widgets;

use App\Models\Investment;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class InvestmentChart extends ChartWidget
{
    protected ?string $heading = 'Инвестиции по месец';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = Investment::select(
            DB::raw("DATE_FORMAT(invested_at, '%Y-%m') as month"),
            DB::raw('SUM(amount) as total'),
            DB::raw('COUNT(*) as count')
        )
            ->where('invested_at', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->groupByRaw("DATE_FORMAT(invested_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        $months = [];
        $amounts = [];
        $names = ['Яну', 'Фев', 'Мар', 'Апр', 'Май', 'Юни', 'Юли', 'Авг', 'Сеп', 'Окт', 'Ное', 'Дек'];

        for ($i = 5; $i >= 0; $i--) {
            $key = Carbon::now()->subMonths($i)->format('Y-m');
            $monthNum = (int) Carbon::now()->subMonths($i)->format('m');
            $months[] = $names[$monthNum - 1];
            $found = $data->firstWhere('month', $key);
            $amounts[] = $found ? (float) $found->total : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Инвестиции (€)',
                    'data' => $amounts,
                    'backgroundColor' => '#22C55E',
                    'borderColor' => '#22C55E',
                ],
            ],
            'labels' => $months,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
