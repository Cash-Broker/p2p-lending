<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Wallet;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * «Сумарно инвестирани и свободни», pinned above the users table (boss
 * 2026-08-11: «при много потребители ще се наложи да скролва много надолу…
 * по-добре това горе да го качим»). The column summaries live at the
 * BOTTOM of the table, which stops being readable as soon as the admin
 * raises the rows-per-page.
 *
 * The figures are not a separate report: `InteractsWithPageTable` hands us
 * the table's own filtered + searched query (minus pagination), so the two
 * cards always describe exactly the rows underneath them.
 */
class UserMoneyOverview extends BaseWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListUsers::class;
    }

    protected function getStats(): array
    {
        // reorder() drops the table's ORDER BY — it is dead weight (and in
        // some engines illegal) inside an IN (...) subquery.
        $userIds = $this->getPageTableQuery()->reorder()->select('users.id');

        $totals = Wallet::whereIn('user_id', $userIds)
            ->selectRaw('COALESCE(SUM(invested), 0) as total_invested')
            ->selectRaw('COALESCE(SUM(available), 0) as total_available')
            ->first();

        return [
            Stat::make('Инвестирани общо', static::money($totals?->total_invested))
                ->description('По текущия филтър и търсене')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Свободни общо', static::money($totals?->total_available))
                ->description('По текущия филтър и търсене')
                ->icon('heroicon-o-wallet')
                ->color('primary'),
        ];
    }

    /**
     * Display only — the ledger never reads these figures back. Formatted
     * like every other amount in the panel: «104 150,00 €».
     */
    protected static function money(mixed $amount): string
    {
        return Number::currency((float) ($amount ?? 0), 'EUR', 'bg');
    }
}
