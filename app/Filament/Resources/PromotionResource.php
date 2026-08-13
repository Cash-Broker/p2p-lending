<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromotionResource\Pages;
use App\Models\Loan;
use App\Models\LoanPromotion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Flash promo campaigns admin (Reni 2026-08-14): «кредити с висока
 * доходност за събиране на пари за кратко време... авансово изплащане на
 * бонус и офертата валидна за 60 мин».
 *
 * Deliberately create + cancel ONLY — no edit page. A live promo's terms
 * must not drift under investors' feet (same freeze philosophy as
 * investment snapshots); wrong promo ⇒ «Прекрати» + create a new one.
 * Deletion is blocked too: bonus_paid_total + audit_logs are the money
 * trail for paid bonuses.
 */
class PromotionResource extends Resource
{
    protected static ?string $model = LoanPromotion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Промо оферти';

    protected static string|UnitEnum|null $navigationGroup = 'Финанси';

    protected static ?int $navigationSort = 6;

    protected static ?string $pluralModelLabel = 'Промо оферти';

    protected static ?string $modelLabel = 'Промо оферта';

    /** Duration presets for the ghost field («офертата валидна за 60 мин»). */
    public const DURATION_OPTIONS = [
        30 => '30 минути',
        60 => '60 минути',
        120 => '2 часа',
        240 => '4 часа',
        1440 => '24 часа',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Нова промо оферта')->schema([
                Forms\Components\Select::make('loan_id')
                    ->label('Кредит')
                    ->required()
                    ->native(false)
                    ->options(function () {
                        return Loan::query()
                            ->whereIn('status', Loan::FUNDABLE_STATUSES)
                            // Private (link-only) loans are not promotable — the
                            // panel + bell would broadcast them to everyone.
                            ->where('visibility', Loan::VISIBILITY_PUBLIC)
                            ->orderByDesc('id')
                            ->get()
                            ->filter(fn (Loan $loan) => bccomp(bcsub($loan->fundingCap(), (string) $loan->funded_amount, 2), '0', 2) > 0)
                            ->mapWithKeys(fn (Loan $loan) => [
                                $loan->id => sprintf('#%d — %s, %s € (свободно %s €)',
                                    $loan->id, $loan->type, $loan->amount,
                                    bcsub($loan->fundingCap(), (string) $loan->funded_amount, 2)),
                            ]);
                    })
                    ->rules([
                        fn () => function (string $attribute, $value, \Closure $fail) {
                            if (LoanPromotion::query()->where('loan_id', $value)->running()->exists()) {
                                $fail('Този кредит вече има активна промо оферта — прекратете я първо.');
                            }
                        },
                    ])
                    ->helperText('Само кредити, отворени за инвестиране и със свободен капацитет.'),

                Forms\Components\TextInput::make('bonus_percent')
                    ->label('Авансов бонус (% от инвестицията)')
                    ->required()
                    ->numeric()
                    ->minValue(0.1)
                    ->maxValue(10)
                    ->step(0.1)
                    ->suffix('%')
                    ->helperText('Изплаща се ВЕДНАГА в «Свободни» при инвестиция по време на промото. Максимум 10%.'),

                // Ghost field — CreatePromotion turns it into starts_at/ends_at.
                Forms\Components\Select::make('duration_minutes')
                    ->label('Валидност')
                    ->required()
                    ->native(false)
                    ->options(self::DURATION_OPTIONS)
                    ->default(60)
                    ->dehydrated(false)
                    ->helperText('Прозорецът тръгва от момента на създаване.'),

                Forms\Components\TextInput::make('budget_cap')
                    ->label('Таван на общия бонус (по избор)')
                    ->numeric()
                    ->minValue(1)
                    ->suffix('€')
                    ->helperText('Общо изплатени бонуси по тази промоция не могат да надхвърлят тавана. Празно = без таван.'),
            ])->columns(2),

            Section::make()->schema([
                Forms\Components\Placeholder::make('promo_note')
                    ->label('')
                    ->content('⚡ При създаване всички инвеститори получават известие в звънчето. Промоцията не се редактира — при грешка я прекратете и създайте нова.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('loan_id')
                    ->label('Кредит')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->sortable(),
                Tables\Columns\TextColumn::make('bonus_percent')
                    ->label('Бонус')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.').' %'),
                Tables\Columns\TextColumn::make('starts_at')->label('От')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->label('До')->dateTime('d.m.Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('bonus_paid_total')
                    ->label('Изплатени бонуси')
                    ->formatStateUsing(fn ($state, LoanPromotion $record) => $record->budget_cap !== null
                        ? "{$state} € / {$record->budget_cap} €"
                        : "{$state} €"),
                Tables\Columns\TextColumn::make('status_label')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (LoanPromotion $record) => match (true) {
                        $record->cancelled_at !== null => 'Прекратена',
                        $record->ends_at->isPast() => 'Изтекла',
                        $record->starts_at->isFuture() => 'Предстояща',
                        default => 'Активна',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Активна' => 'success',
                        'Предстояща' => 'info',
                        'Изтекла' => 'gray',
                        'Прекратена' => 'danger',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Action::make('cancel_promo')
                    ->label('Прекрати')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LoanPromotion $record) => $record->isRunning())
                    ->requiresConfirmation()
                    ->modalHeading('Прекратяване на промо офертата')
                    ->modalDescription('Панелът изчезва от таблата на инвеститорите веднага; вече изплатени бонуси остават. Действието е необратимо.')
                    ->action(function (LoanPromotion $record) {
                        try {
                            DB::transaction(function () use ($record) {
                                $promo = LoanPromotion::lockForUpdate()->findOrFail($record->id);
                                if ($promo->cancelled_at !== null) {
                                    return;
                                }
                                $promo->forceFill(['cancelled_at' => now()])->save();
                            });

                            Notification::make()->title('Промо офертата е прекратена')->success()->send();
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()->title('Грешка при прекратяване')->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
        ];
    }
}
