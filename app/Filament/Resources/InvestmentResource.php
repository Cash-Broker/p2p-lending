<?php

namespace App\Filament\Resources;

use App\Enums\PayoutType;
use App\Filament\Resources\InvestmentResource\Pages;
use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Global read-only register of ALL investments (boss 2026-08-10: «трябва
 * да виждам кой какви пари в какви кредити е инвестирал, в какъв план»).
 * Until now investments were visible only per-loan (the LoanResource
 * relation manager) — the dashboard total had no drill-down.
 *
 * The Сума column carries a live Sum summarizer, so filtering (по план /
 * инвеститор / кредит / период) re-totals at the bottom — «инвестирани
 * 3200 €» decomposes into who/where/what with two clicks.
 *
 * NB: name-collides with App\Http\Resources\InvestmentResource (the API
 * JsonResource) — different namespaces, never import both in one file.
 */
class InvestmentResource extends Resource
{
    protected static ?string $model = Investment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Инвестиции';

    protected static string|UnitEnum|null $navigationGroup = 'Финанси';

    protected static ?string $pluralModelLabel = 'Инвестиции';

    protected static ?string $modelLabel = 'Инвестиция';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'loan', 'loanOffer', 'contract']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')
                    ->searchable()
                    ->description(fn (Investment $record): ?string => $record->user?->email),
                Tables\Columns\TextColumn::make('loan_id')->label('Кредит')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->url(fn (Investment $record): ?string => $record->loan_id
                        ? LoanResource::getUrl('edit', ['record' => $record->loan_id])
                        : null)
                    ->color('info')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('payout_type')->label('План')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? 'Легаси')
                    ->colors([
                        'info' => fn ($state) => $state === PayoutType::Amortizing,
                        'success' => fn ($state) => $state === PayoutType::InterestOnly,
                        'warning' => fn ($state) => $state === PayoutType::Capitalized,
                    ]),
                Tables\Columns\TextColumn::make('interest_rate')->label('Лихва')
                    ->formatStateUsing(fn ($state) => $state !== null ? rtrim(rtrim((string) $state, '0'), '.').' %' : '—'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->sortable()
                    // Live total under the column — follows every filter, so
                    // the dashboard figure decomposes right here.
                    ->summarize(Sum::make()->label('Общо')->money('EUR')),
                Tables\Columns\TextColumn::make('invested_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('contract.accepted_at')->label('Съгласие с договора')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('invested_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('payout_type')->label('План')
                    ->options(PayoutType::labels()),
                Tables\Filters\SelectFilter::make('user_id')->label('Инвеститор')
                    ->options(fn () => User::where('role', 'investor')->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('loan_id')->label('Кредит')
                    ->options(fn () => Loan::latest('id')->limit(200)->get()
                        ->mapWithKeys(fn ($loan) => [$loan->id => "#{$loan->id} · {$loan->amount} €"])->all())
                    ->searchable(),
                Tables\Filters\Filter::make('invested_between')
                    ->form([
                        DatePicker::make('from')->label('От'),
                        DatePicker::make('to')->label('До'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q) => $q->where('invested_at', '>=', $data['from']))
                        ->when($data['to'], fn ($q) => $q->where('invested_at', '<=', $data['to'].' 23:59:59'))),
            ])
            ->recordActions([
                Action::make('contract')
                    ->label('Договор')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Investment $record): string => route('admin.investment-contract', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Investment $record): bool => $record->contract !== null),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInvestments::route('/')];
    }
}
