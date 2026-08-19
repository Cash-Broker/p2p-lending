<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Wallet;
use App\Services\AccruedEarningsService;
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

    /**
     * Shown when there is nothing hiding outside the two figures.
     */
    private const SCOPE_NOTE = 'По текущия филтър и търсене';

    protected function getStats(): array
    {
        // reorder() drops the table's ORDER BY — it is dead weight (and in
        // some engines illegal) inside an IN (...) subquery.
        $userIds = $this->getPageTableQuery()->reorder()->select('users.id');

        $totals = Wallet::whereIn('user_id', $userIds)
            ->selectRaw('COALESCE(SUM(invested), 0) as total_invested')
            ->selectRaw('COALESCE(SUM(available), 0) as total_available')
            ->selectRaw('COALESCE(SUM(reserved), 0) as total_reserved')
            ->selectRaw('COALESCE(SUM(accrued), 0) as total_accrued')
            ->first();

        // `earned` is deliberately absent from every figure here: it is a
        // cumulative counter credited ALONGSIDE `available` (WalletService
        // credits both on every interest payment), not a pot of money.
        // Adding it anywhere double-counts the same euros.
        // Interest the investors have ALREADY earned but not yet been paid —
        // the platform's live liability (Reni 2026-08-19: «важно ми е да си
        // следя паричните потоци»).
        //
        // NOT the remaining scheduled interest: that assumes every loan runs
        // to term with no early or partial repayment, «което никога не е
        // така», and it read as a scary number that means nothing today. This
        // is the exact figure each investor sees as «Текуща печалба», summed —
        // same service, so the two can never drift apart.
        $accruedInterest = app(AccruedEarningsService::class)->accruedByPlan($userIds)['total'];

        return [
            // Each card is exactly the SUM of the column beneath it — that is
            // the whole point («да не се налага да ги събирам»), so the value
            // must never quietly become a different figure. The two buckets
            // that live outside the columns are disclosed underneath instead,
            // and only when they actually hold money.
            Stat::make('Инвестирани общо', static::money($totals?->total_invested))
                ->description(self::SCOPE_NOTE)
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Свободни общо', static::money($totals?->total_available))
                ->description(static::note($totals?->total_reserved, 'в процес на теглене'))
                ->icon('heroicon-o-wallet')
                ->color('primary'),

            // Not a wallet bucket: interest earned to date across every open
            // position. The `accrued` bucket is the slice of it already parked
            // in investors' balances (capitalized plans), so it is disclosed
            // underneath as a subset — never added on top.
            Stat::make('Текущо начислени лихви', static::money($accruedInterest))
                ->description(static::note($totals?->total_accrued, 'от тях вече в балансите'))
                ->icon('heroicon-o-arrow-trending-up')
                ->color('warning'),
        ];
    }

    /**
     * Money parked in `reserved` (a withdrawal is on its way out) or in
     * `accrued` (interest promised but not yet released) belongs to nobody's
     * column, so without this line the cards would read as «everything the
     * platform holds» while quietly missing it.
     */
    protected static function note(mixed $amount, string $label): string
    {
        $amount = (string) ($amount ?? '0');

        return bccomp($amount, '0', 2) > 0
            ? '+ '.static::money($amount).' '.$label
            : self::SCOPE_NOTE;
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
