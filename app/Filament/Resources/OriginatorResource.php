<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OriginatorResource\Pages;
use App\Models\Originator;
use App\Models\PlatformSetting;
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
            Forms\Components\Toggle::make('buyback')
                ->label('Buyback гаранция')
                ->default(false)
                ->live(),

            // F2 — per-originator buyback config. Only relevant when
            // buyback toggle is on; hidden otherwise. NULL → fallback
            // to platform_settings defaults (see helper text).
            Forms\Components\Select::make('buyback_coverage')
                ->label('Покритие (тип)')
                ->options([
                    'principal_only'            => 'Само главница',
                    'principal_plus_interest'   => 'Главница + планирана лихва',
                ])
                ->nullable()
                ->placeholder(fn () => 'Платформен default: '
                    . (PlatformSetting::get('buyback_default_coverage') === 'principal_only'
                        ? 'Само главница' : 'Главница + планирана лихва'))
                ->helperText('Без избор → използва платформения default.')
                ->visible(fn (Forms\Get $get) => $get('buyback') === true),

            Forms\Components\TextInput::make('buyback_trigger_days')
                ->label('Buyback trigger (дни)')
                ->numeric()
                ->minValue(0)
                ->maxValue(365)
                ->nullable()
                ->placeholder(fn () => 'Платформен default: '
                    . PlatformSetting::get('buyback_default_trigger_days', 60))
                ->helperText('Дни след became_late_at преди buyback да стане eligible. 0 = веднага. Без стойност → платформен default.')
                ->visible(fn (Forms\Get $get) => $get('buyback') === true),

            Forms\Components\FileUpload::make('logo_path')->label('Лого')
                ->image()
                // Explicit MIME allow-list — Filament's image() default permits SVG,
                // which can carry JavaScript and run when investors view loan listings.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2048) // 2MB cap
                ->directory('originator-logos')
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Име')->searchable(),
                Tables\Columns\IconColumn::make('buyback')->label('Buyback')->boolean(),
                Tables\Columns\TextColumn::make('buyback_coverage')
                    ->label('Покритие')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'principal_only' => 'Гл.',
                        'principal_plus_interest' => 'Гл. + л.',
                        null => 'Default',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'principal_plus_interest' => 'success',
                        'principal_only' => 'info',
                        null => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('buyback_trigger_days')
                    ->label('Trigger (дни)')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? (string) $state : 'default')
                    ->toggleable(),
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
