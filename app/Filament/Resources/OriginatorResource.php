<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OriginatorResource\Pages;
use App\Models\Originator;
use BackedEnum;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OriginatorResource extends Resource
{
    protected static ?string $model = Originator::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Оригинатори';
    protected static ?string $pluralModelLabel = 'Оригинатори';
    protected static ?string $modelLabel = 'Оригинатор';
    protected static ?int $navigationSort = 7;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Име')->required(),
            Forms\Components\Textarea::make('description')->label('Описание')->required(),
            Forms\Components\TextInput::make('website')->label('Уебсайт')->url()->nullable(),
            Forms\Components\Toggle::make('buyback')->label('Buyback гаранция')->default(false),
            Forms\Components\FileUpload::make('logo_path')->label('Лого')->image()->directory('originator-logos')->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable(),
                Tables\Columns\IconColumn::make('buyback')->label('Buyback')->boolean(),
                Tables\Columns\TextColumn::make('loans_count')->label('Кредити')->counts('loans'),
                Tables\Columns\TextColumn::make('created_at')->label('Създаден')->date('d.m.Y'),
            ])
            ->actions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOriginators::route('/'), 'create' => Pages\CreateOriginator::route('/create'), 'edit' => Pages\EditOriginator::route('/{record}/edit')];
    }
}
