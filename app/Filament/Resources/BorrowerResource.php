<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BorrowerResource\Pages;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BorrowerResource extends Resource
{
    protected static ?string $model = Borrower::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Кредитополучатели';

    protected static ?string $pluralModelLabel = 'Кредитополучатели';

    protected static ?string $modelLabel = 'Кредитополучател';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Лични данни')->schema([
                Forms\Components\TextInput::make('full_name')->label('Пълно име')->required(),
                Forms\Components\TextInput::make('address')->label('Адрес')->required(),
                Forms\Components\TextInput::make('phone')->label('Телефон')->required(),
            ])->columns(2),
            Section::make('Финансови данни')->schema([
                Forms\Components\TextInput::make('income')->label('Доход (€)')->numeric()->required(),
                Forms\Components\Select::make('credit_score')->label('Кредитен рейтинг')
                    ->options(Borrower::CREDIT_RATINGS)
                    ->placeholder('— без рейтинг —')
                    ->nullable(),
                Forms\Components\Textarea::make('notes')->label('Бележки')->nullable(),
            ])->columns(2),
            // Investor-facing anonymized profile, collected AT creation so
            // «Неопределен» placeholders never reach the site (boss
            // 2026-08-10). On EDIT the profile is managed by its relation
            // manager below — these fields are create-only.
            Section::make('Профил за инвеститора (анонимен, вижда се на сайта)')->schema([
                Forms\Components\Select::make('profile_risk_class')->label('Рисков клас')
                    ->options(['A' => 'A — Нисък', 'B' => 'B — Умерен', 'C' => 'C — Среден', 'D' => 'D — Повишен', 'E' => 'E — Висок'])
                    ->default('C')
                    ->required()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('profile_region')->label('Регион')
                    ->placeholder('напр. Кюстендил')->required()->dehydrated(false),
                Forms\Components\Select::make('profile_loan_purpose')->label('Цел на кредита')
                    ->options(BorrowerAnonymizedProfile::LOAN_PURPOSES)
                    ->required()->dehydrated(false),
                Forms\Components\Select::make('profile_collateral_type')->label('Обезпечение')
                    ->options(BorrowerAnonymizedProfile::COLLATERAL_TYPES)
                    ->placeholder('—')
                    ->nullable()->dehydrated(false),
                Forms\Components\Select::make('profile_age_group')->label('Възрастова група')
                    ->options(BorrowerAnonymizedProfile::AGE_GROUPS)
                    ->placeholder('—')
                    ->nullable()->dehydrated(false),
            ])->columns(2)->hiddenOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Име')->searchable(),
                Tables\Columns\TextColumn::make('personal_id')->label('ЕГН')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? '****'.substr($state, -4) : '—'),
                Tables\Columns\TextColumn::make('loans_count')->label('Кредити')->counts('loans'),
                Tables\Columns\TextColumn::make('created_at')->label('Създаден')->date('d.m.Y'),
            ])
            ->actions([EditAction::make()]);
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
