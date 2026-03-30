<?php

namespace App\Filament\Resources\BorrowerResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AnonymizedProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'anonymizedProfile';
    protected static ?string $title = 'Анонимизиран профил';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Select::make('risk_class')->label('Рисков клас')
                ->options(['A' => 'A — Нисък', 'B' => 'B — Умерен', 'C' => 'C — Среден', 'D' => 'D — Повишен', 'E' => 'E — Висок'])
                ->required(),
            Forms\Components\TextInput::make('region')->label('Регион')->required(),
            Forms\Components\TextInput::make('loan_purpose')->label('Цел на кредита')->required(),
            Forms\Components\TextInput::make('collateral_type')->label('Обезпечение')->nullable(),
            Forms\Components\TextInput::make('age_group')->label('Възрастова група')->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BadgeColumn::make('risk_class')->label('Риск')
                    ->colors(['success' => 'A', 'info' => 'B', 'warning' => 'C', 'danger' => fn ($state) => in_array($state, ['D', 'E'])]),
                Tables\Columns\TextColumn::make('region')->label('Регион'),
                Tables\Columns\TextColumn::make('loan_purpose')->label('Цел'),
                Tables\Columns\TextColumn::make('collateral_type')->label('Обезпечение'),
                Tables\Columns\TextColumn::make('age_group')->label('Възраст'),
            ])
            ->headerActions([\Filament\Actions\CreateAction::make()])
            ->actions([\Filament\Actions\EditAction::make()]);
    }
}
