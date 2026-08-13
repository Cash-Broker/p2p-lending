<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformSettingResource\Pages;
use App\Models\PlatformSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Filament admin for platform-wide settings (currently grace_period_days
 * and late_check_enabled).
 *
 * Access:
 *   - Gated on role=admin via canViewAny / canCreate / canDelete (see
 *     DECISIONS.md — "Admin role consolidation for v1").
 *   - Create/Delete intentionally disabled — settings are seeded by
 *     migration; admin only edits VALUES, never adds/removes keys (a
 *     stray key would have nothing reading it).
 *
 * Audit:
 *   - PlatformSetting uses the `Auditable` trait; every save writes a
 *     row to audit_logs with old/new values, who changed it, IP, UA.
 *
 * Defense in depth:
 *   - Filament form validates grace_period_days ∈ 0–30 client-side AND
 *     server-side (validation rules below).
 *   - DB CHECK constraint (migration F1.1) is the final backstop.
 */
class PlatformSettingResource extends Resource
{
    protected static ?string $model = PlatformSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Настройки';

    protected static string|UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 9;

    protected static ?string $pluralModelLabel = 'Настройки';

    protected static ?string $modelLabel = 'Настройка';

    /**
     * String settings that are really enums — the admin picks from a fixed
     * list instead of free text (the DB CHECK is the backstop). key => options.
     */
    public const STRING_CHOICES = [
        'dashboard_earned_mode' => [
            'daily' => 'Дневно — сумата нараства веднъж на ден',
            'live' => 'На живо — брояч в реално време',
        ],
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /** Settings are seeded — never created from the UI. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Settings are referenced by code; deleting one would silently break automation. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()->schema([
                Forms\Components\TextInput::make('key')
                    ->label('Ключ')
                    ->disabled() // immutable once seeded
                    ->dehydrated(false), // never write to DB

                Forms\Components\TextInput::make('type')
                    ->label('Тип')
                    ->disabled()
                    ->dehydrated(false),

                // Type-aware value editors. Filament shows ALL of these on the
                // form; only the one matching `type` is actually used. Cleaner
                // than a polymorphic widget, simpler to maintain.

                // bool → Toggle
                Forms\Components\Toggle::make('value_bool')
                    ->label('Стойност')
                    ->visible(fn ($record) => $record?->type === 'bool')
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, $record) => $component->state(
                        $record && filter_var($record->value, FILTER_VALIDATE_BOOLEAN)
                    )),

                // int → numeric TextInput, with grace_period_days range guard
                Forms\Components\TextInput::make('value_int')
                    ->label('Стойност')
                    ->numeric()
                    ->visible(fn ($record) => $record?->type === 'int')
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->value))
                    ->rules(fn ($record) => $record?->key === 'grace_period_days'
                        ? ['integer', 'min:0', 'max:30']
                        : ['integer'])
                    ->helperText(fn ($record) => $record?->key === 'grace_period_days'
                        ? 'Брой дни след падежа преди вноска да бъде маркирана като закъсняла. Диапазон 0–30.'
                        : null),

                // float → numeric with decimals
                Forms\Components\TextInput::make('value_float')
                    ->label('Стойност')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn ($record) => $record?->type === 'float')
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->value)),

                // enum-like string → Select with the allowed values only
                Forms\Components\Select::make('value_choice')
                    ->label('Стойност')
                    ->visible(fn ($record) => isset(self::STRING_CHOICES[$record?->key]))
                    ->options(fn ($record) => self::STRING_CHOICES[$record?->key] ?? [])
                    ->required()
                    ->native(false)
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->value)),

                // string / json → Textarea
                Forms\Components\Textarea::make('value_text')
                    ->label('Стойност')
                    ->rows(4)
                    ->visible(fn ($record) => in_array($record?->type, ['string', 'json'])
                        && ! isset(self::STRING_CHOICES[$record?->key]))
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->value)),

                Forms\Components\Textarea::make('description')
                    ->label('Описание')
                    ->rows(2)
                    ->disabled()
                    ->dehydrated(false),

                // Real `value` field — hidden, mutated in mutateFormDataBeforeSave
                // (registered on the EditPlatformSetting page).
                Forms\Components\Hidden::make('value'),
            ])->columns(2),

            Section::make()->schema([
                Forms\Components\Placeholder::make('warning')
                    ->label('')
                    ->content('⚠️ Промяната влиза в сила при следващото daily late-check изпълнение (03:30). Аудит запис се записва автоматично.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('Ключ')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('value')->label('Стойност')->wrap(),
                Tables\Columns\TextColumn::make('type')->label('Тип')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('description')->label('Описание')->limit(60)->wrap()->color('gray'),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->defaultSort('key')
            ->actions([
                EditAction::make()->label('Редактирай'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformSettings::route('/'),
            'edit' => Pages\EditPlatformSetting::route('/{record}/edit'),
        ];
    }
}
