<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Investment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The user's OWN investments, right on their admin profile (boss
 * 2026-08-10: «като го отвориш, трябва само неговите инвестиции да
 * вижда, а не смесица с други»). Read-only; the live Сума total shows
 * how much of the platform's money is this person's.
 */
class InvestmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investments';

    protected static ?string $title = 'Инвестиции';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['loan', 'contract']))
            ->columns([
                Tables\Columns\TextColumn::make('loan_id')->label('Кредит')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->description(fn (Investment $record): ?string => $record->loan?->contract_number),
                Tables\Columns\TextColumn::make('payout_type')->label('План')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? 'Легаси'),
                Tables\Columns\TextColumn::make('interest_rate')->label('Лихва')
                    ->formatStateUsing(fn ($state) => $state !== null ? rtrim(rtrim((string) $state, '0'), '.').' %' : '—'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')
                    ->summarize(Sum::make()->label('Общо')->money('EUR')),
                Tables\Columns\TextColumn::make('invested_at')->label('Дата')->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('contract.accepted_at')->label('Съгласие с договора')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('contract')
                    ->label('Договор')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Investment $record): string => route('admin.investment-contract', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Investment $record): bool => $record->contract !== null),
            ])
            ->defaultSort('invested_at', 'desc');
    }
}
