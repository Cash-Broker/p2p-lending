<?php

namespace App\Filament\Widgets;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Loan-overhaul Phase 7 — "падежи за разплащане".
 *
 * Surfaces the installments that need settling now: unpaid (pending/late) rows
 * of active/late loans due within the next week (plus anything overdue). Today's
 * rows are highlighted amber, overdue red — so the admin sees at a glance who to
 * pay today. Read-only; no distribution impact.
 */
class UpcomingDueDatesWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    /** Extracted so it can be asserted directly in tests (no Livewire harness). */
    public static function dueInstallmentsQuery(): Builder
    {
        return AmortizationSchedule::query()
            ->whereIn('status', ['pending', 'late'])
            ->whereDate('due_date', '<=', now()->addDays(7))
            ->whereHas('loan', fn (Builder $query) => $query->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_LATE]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Падежи за разплащане')
            ->query(static::dueInstallmentsQuery()->with('loan.borrower')->orderBy('due_date'))
            ->emptyStateHeading('Няма вноски за разплащане')
            ->columns([
                Tables\Columns\TextColumn::make('due_date')->label('Падеж')->date('d.m.Y')
                    ->badge()
                    ->color(fn (AmortizationSchedule $record) => $record->due_date->isToday()
                        ? 'warning'
                        : ($record->due_date->isPast() ? 'danger' : 'gray')),
                Tables\Columns\TextColumn::make('loan.id')->label('Кредит #'),
                Tables\Columns\TextColumn::make('loan.borrower.full_name')->label('Кредитополучател'),
                Tables\Columns\TextColumn::make('total')->label('Вноска')->money('EUR'),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Предстои', 'late' => 'Закъснение', default => $state,
                    })
                    ->colors(['warning' => 'pending', 'danger' => 'late']),
            ])
            ->paginated([10, 25, 50]);
    }
}
