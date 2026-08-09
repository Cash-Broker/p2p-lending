<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\Investment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvestmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investments';

    protected static ?string $title = 'Инвестиции';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contract', 'loanOffer']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR'),
                Tables\Columns\TextColumn::make('payout_type')
                    ->label('План')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                Tables\Columns\TextColumn::make('interest_rate')
                    ->label('Лихва')
                    ->formatStateUsing(fn ($state) => $state !== null ? rtrim(rtrim((string) $state, '0'), '.').' %' : '—'),
                Tables\Columns\TextColumn::make('invested_at')->label('Дата')->date('d.m.Y H:i'),
                // Click-wrap acceptance evidence — «човекът Х се е съгласил».
                // The invest click concluded the contract; no signatures
                // (client decision 2026-08-09).
                Tables\Columns\TextColumn::make('contract.accepted_at')
                    ->label('Съгласие с договора')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('— (преди договорите)')
                    ->tooltip(fn (Investment $record): ?string => $record->contract
                        ? 'Прието електронно (клик „Инвестирай“) · IP: '.($record->contract->ip_address ?? 'н/д')
                        : null),
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
