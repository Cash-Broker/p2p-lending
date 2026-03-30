<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BorrowerResource\Pages;
use App\Models\Borrower;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BorrowerResource extends Resource
{
    protected static ?string $model = Borrower::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Кредитополучатели';
    protected static ?string $pluralModelLabel = 'Кредитополучатели';
    protected static ?string $modelLabel = 'Кредитополучател';
    protected static ?int $navigationSort = 6;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('Лични данни')->schema([
                Forms\Components\TextInput::make('full_name')->label('Пълно име')->required(),
                Forms\Components\TextInput::make('personal_id')->label('ЕГН / ID')->required(),
                Forms\Components\TextInput::make('address')->label('Адрес')->required(),
                Forms\Components\TextInput::make('phone')->label('Телефон')->required(),
            ])->columns(2),
            \Filament\Schemas\Components\Section::make('Финансови данни')->schema([
                Forms\Components\TextInput::make('income')->label('Доход (€)')->numeric()->required(),
                Forms\Components\TextInput::make('credit_score')->label('Кредитен рейтинг')->numeric()->nullable(),
                Forms\Components\Textarea::make('notes')->label('Бележки')->nullable(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Име')->searchable(),
                Tables\Columns\TextColumn::make('personal_id')->label('ЕГН')
                    ->formatStateUsing(fn (string $state) => '****' . substr($state, -4)),
                Tables\Columns\TextColumn::make('loans_count')->label('Кредити')->counts('loans'),
                Tables\Columns\TextColumn::make('created_at')->label('Създаден')->date('d.m.Y'),
            ])
            ->actions([\Filament\Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            BorrowerResource\RelationManagers\AnonymizedProfileRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBorrowers::route('/'),
            'create' => Pages\CreateBorrower::route('/create'),
            'edit' => Pages\EditBorrower::route('/{record}/edit'),
        ];
    }
}
