<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvestmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'investments';
    protected static ?string $title = 'Инвестиции';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR'),
                Tables\Columns\TextColumn::make('invested_at')->label('Дата')->date('d.m.Y H:i'),
            ])
            ->defaultSort('invested_at', 'desc');
    }
}
