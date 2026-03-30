<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationLabel = 'Транзакции';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?string $pluralModelLabel = 'Транзакции';
    protected static ?string $modelLabel = 'Транзакция';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Потребител')->searchable(),
                Tables\Columns\BadgeColumn::make('type')->label('Тип')
                    ->colors(['success' => fn ($state) => in_array($state, ['deposit', 'repayment_principal', 'repayment_interest']), 'danger' => fn ($state) => in_array($state, ['withdrawal', 'fee']), 'info' => 'investment']),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Описание')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(array_combine(Transaction::TYPES, array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), Transaction::TYPES))),
                Tables\Filters\SelectFilter::make('user_id')->label('Потребител')->options(User::where('role', 'investor')->pluck('name', 'id'))->searchable(),
                Tables\Filters\Filter::make('date_range')
                    ->form([\Filament\Forms\Components\DatePicker::make('from')->label('От'), \Filament\Forms\Components\DatePicker::make('to')->label('До')])
                    ->query(fn ($query, array $data) => $query->when($data['from'], fn ($q) => $q->where('created_at', '>=', $data['from']))->when($data['to'], fn ($q) => $q->where('created_at', '<=', $data['to'] . ' 23:59:59'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTransactions::route('/')];
    }
}
