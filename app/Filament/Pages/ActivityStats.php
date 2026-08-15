<?php

namespace App\Filament\Pages;

use App\Models\Investment;
use App\Models\User;
use App\Models\UserVisitDay;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Активност» (Yordan 2026-08-15): the whole investor base's engagement on
 * ONE screen — «в един момент ще станат 100 потребителя», clicking into
 * profiles doesn't scale. Header stats show the platform pulse; the table
 * lists every investor with visits (7/30 days, Sofia calendar), last-seen
 * and deployed money, sortable by every engagement column.
 *
 * Read-only analytics — no actions, no money paths.
 */
class ActivityStats extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Активност';

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Активност на инвеститорите';

    protected string $view = 'filament.pages.activity-stats';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /** Header stats: the platform pulse (Sofia calendar days). */
    public function getStats(): array
    {
        $today = now()->timezone('Europe/Sofia')->toDateString();
        $yesterday = now()->timezone('Europe/Sofia')->subDay()->toDateString();
        $weekAgo = now()->timezone('Europe/Sofia')->subDays(6)->toDateString();

        $todayRow = UserVisitDay::where('visit_date', $today)
            ->selectRaw('COALESCE(SUM(entries), 0) as entries, COUNT(DISTINCT user_id) as users')->first();
        $yesterdayRow = UserVisitDay::where('visit_date', $yesterday)
            ->selectRaw('COALESCE(SUM(entries), 0) as entries, COUNT(DISTINCT user_id) as users')->first();
        $weekRow = UserVisitDay::where('visit_date', '>=', $weekAgo)
            ->selectRaw('COALESCE(SUM(entries), 0) as entries, COUNT(DISTINCT user_id) as users')->first();

        $invested7d = (string) Investment::where('invested_at', '>=', now()->subDays(7))->sum('amount');

        return [
            ['label' => 'Влизания днес', 'value' => "{$todayRow->entries} (от {$todayRow->users} инв.)"],
            ['label' => 'Влизания вчера', 'value' => "{$yesterdayRow->entries} (от {$yesterdayRow->users} инв.)"],
            ['label' => 'Влизания 7 дни', 'value' => "{$weekRow->entries} (от {$weekRow->users} инв.)"],
            ['label' => 'Инвестирано 7 дни', 'value' => number_format((float) $invested7d, 2, ',', ' ').' €'],
        ];
    }

    public function table(Table $table): Table
    {
        $sofia7 = now()->timezone('Europe/Sofia')->subDays(6)->toDateString();
        $sofia30 = now()->timezone('Europe/Sofia')->subDays(29)->toDateString();

        return $table
            ->query(
                User::query()
                    ->where('role', 'investor')
                    ->addSelect([
                        'visits_7d' => UserVisitDay::selectRaw('COALESCE(SUM(entries), 0)')
                            ->whereColumn('user_id', 'users.id')
                            ->where('visit_date', '>=', $sofia7),
                        'visits_30d' => UserVisitDay::selectRaw('COALESCE(SUM(entries), 0)')
                            ->whereColumn('user_id', 'users.id')
                            ->where('visit_date', '>=', $sofia30),
                    ])
                    ->with('wallet')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Инвеститор')
                    ->description(fn (User $r) => $r->email)
                    ->searchable(['name', 'email']),
                Tables\Columns\TextColumn::make('dashboard_seen_at')
                    ->label('Последно в платформата')
                    ->state(fn (User $r) => $r->dashboard_seen_at
                        ? $r->dashboard_seen_at->timezone('Europe/Sofia')->format('d.m.Y H:i')
                        : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('visits_7d')
                    ->label('Влизания 7д')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('visits_30d')
                    ->label('Влизания 30д')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('wallet.invested')
                    ->label('Инвестирани')
                    ->state(fn (User $r) => number_format((float) ($r->wallet->invested ?? 0), 2, ',', ' ').' €')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('wallet.available')
                    ->label('Свободни')
                    ->state(fn (User $r) => number_format((float) ($r->wallet->available ?? 0), 2, ',', ' ').' €')
                    ->alignEnd(),
            ])
            ->defaultSort('dashboard_seen_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
