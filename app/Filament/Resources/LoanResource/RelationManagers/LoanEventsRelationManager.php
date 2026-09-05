<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\LoanEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only timeline of lifecycle events for a single loan.
 *
 * loan_events is append-only (DB triggers + app-level guard from F1
 * Step 2 model), so no Edit/Delete actions are exposed in the UI —
 * Filament would just throw on attempt anyway.
 *
 * Data shown:
 *   occurred_at, event_type badge, from→to status pair, who triggered
 *   it (system or admin user name), pretty-printed metadata preview
 *   with a JSON tooltip on hover.
 *
 * Newest events first — the timeline reads top-down.
 */
class LoanEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Timeline на събития';

    /** Append-only: no create/edit/delete actions are wired up. */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('Кога')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('event_type')
                    ->label('Събитие')
                    ->formatStateUsing(fn (string $state, LoanEvent $record) => match ($state) {
                        LoanEvent::TYPE_WENT_LATE => 'Стана закъснял',
                        LoanEvent::TYPE_RECOVERED_FROM_LATE => 'Възстановен от late',
                        LoanEvent::TYPE_WENT_DEFAULT => 'Просрочен',
                        LoanEvent::TYPE_BUYBACK_TRIGGERED => 'Готов за изкупуване',
                        LoanEvent::TYPE_BUYBACK_COMPLETED => 'Buyback изпълнен',
                        LoanEvent::TYPE_EARLY_REPAYMENT_REQUESTED => 'Поискано предсрочно',
                        LoanEvent::TYPE_EARLY_REPAYMENT_COMPLETED => 'Завършено предсрочно',
                        LoanEvent::TYPE_FEE_APPLIED => 'Приложена такса',
                        // PAY-13: pause/resume ride status_changed with metadata.kind.
                        LoanEvent::TYPE_STATUS_CHANGED => match ($record->metadata['kind'] ?? null) {
                            'payouts_paused' => 'Авансирането спряно',
                            'payouts_resumed' => 'Авансирането възобновено',
                            default => 'Статус променен',
                        },
                        default => $state,
                    })
                    ->colors([
                        'warning' => LoanEvent::TYPE_WENT_LATE,
                        'success' => LoanEvent::TYPE_RECOVERED_FROM_LATE,
                        'danger' => LoanEvent::TYPE_WENT_DEFAULT,
                        'info' => fn ($state) => str_starts_with($state, 'buyback_'),
                        'primary' => fn ($state) => str_starts_with($state, 'early_repayment_'),
                        'gray' => fn ($state) => in_array($state, [LoanEvent::TYPE_FEE_APPLIED, LoanEvent::TYPE_STATUS_CHANGED]),
                    ]),

                Tables\Columns\TextColumn::make('transition')
                    ->label('Преход')
                    ->getStateUsing(fn (LoanEvent $r) => $r->from_status && $r->to_status
                        ? "{$r->from_status} → {$r->to_status}"
                        : '—')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('triggered_by')
                    ->label('Източник')
                    ->formatStateUsing(fn (LoanEvent $r) => $r->triggered_by === LoanEvent::TRIGGERED_BY_ADMIN
                        ? ('admin: '.($r->triggeredByUser->name ?? "#{$r->triggered_by_user_id}"))
                        : 'система'),

                // Pretty-print metadata as "key: value, key: value" with a
                // JSON tooltip for the full structure. Per spec.
                Tables\Columns\TextColumn::make('metadata')
                    ->label('Детайли')
                    ->formatStateUsing(fn ($state) => $state
                        ? collect($state)
                            ->map(fn ($v, $k) => "{$k}: ".(is_scalar($v) ? $v : json_encode($v)))
                            ->join(', ')
                        : '—')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->metadata
                        ? json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : null),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([])  // none
            ->actions([])         // none — append-only
            ->bulkActions([]);    // none
    }
}
