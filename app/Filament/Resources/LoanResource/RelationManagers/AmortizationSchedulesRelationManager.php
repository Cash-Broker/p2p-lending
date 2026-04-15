<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\Loan;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AmortizationSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'amortizationSchedules';
    protected static ?string $title = 'Погасителен план';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\DatePicker::make('due_date')->label('Дата')->required(),
            Forms\Components\TextInput::make('principal')->label('Главница (€)')->numeric()->required(),
            Forms\Components\TextInput::make('interest')->label('Лихва (€)')->numeric()->required(),
            Forms\Components\TextInput::make('total')->label('Общо (€)')->numeric()->required(),
            Forms\Components\Select::make('status')->label('Статус')
                ->options(['pending' => 'Предстои', 'paid' => 'Платено', 'late' => 'Закъснение', 'default' => 'Просрочено'])
                ->default('pending'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('due_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('principal')->label('Главница')->money('EUR'),
                Tables\Columns\TextColumn::make('interest')->label('Лихва')->money('EUR'),
                Tables\Columns\TextColumn::make('total')->label('Общо')->money('EUR'),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Предстои', 'paid' => 'Платено', 'late' => 'Закъснение', 'default' => 'Просрочено', default => $state,
                    })
                    ->colors(['warning' => 'pending', 'success' => 'paid', 'danger' => fn ($state) => in_array($state, ['late', 'default'])]),
                Tables\Columns\TextColumn::make('paid_at')->label('Платено на')->date('d.m.Y'),
            ])
            ->defaultSort('due_date')
            ->headerActions([
                \Filament\Actions\CreateAction::make()->label('Добави вноска')
                    ->visible(fn () => in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()->label('Редактирай')
                    ->visible(fn ($record) => $record->status !== 'paid'
                        && in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
                \Filament\Actions\DeleteAction::make()->label('Изтрий')
                    ->visible(fn ($record) => $record->status === 'pending'
                        && in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
            ]);
    }
}
