<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BonusGrantResource\Pages;
use App\Models\BonusGrant;
use App\Services\BonusService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Read-only register of granted bonuses and what each one is waiting on
 * (Reni 2026-08-18). Grants are created from «Начисли бонус» / the promo
 * engine and released by the `bonuses:release-eligible` cron — the only
 * action here is cancelling one that should never be paid.
 */
class BonusGrantResource extends Resource
{
    protected static ?string $model = BonusGrant::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Бонуси';

    protected static string|UnitEnum|null $navigationGroup = 'Финанси';

    protected static ?string $pluralModelLabel = 'Бонуси';

    protected static ?string $modelLabel = 'Бонус';

    protected static ?int $navigationSort = 4;

    private const DISPLAY_TIMEZONE = 'Europe/Sofia';

    /** Bonuses are granted from the money screens, never typed in here. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', BonusGrant::STATUS_LOCKED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Заключени бонуси, чакащи изпълнение на условието';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')
                    ->searchable()
                    ->description(fn (BonusGrant $record): ?string => $record->user?->email),
                Tables\Columns\TextColumn::make('amount')->label('Бонус')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('base_amount')->label('Изисквана инвестиция')->money('EUR')
                    ->description(fn (BonusGrant $record): string => "след {$record->required_installments} погашения"),
                Tables\Columns\BadgeColumn::make('source')->label('Източник')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        BonusGrant::SOURCE_ADMIN => 'Ръчен', BonusGrant::SOURCE_PROMO => 'Промо', default => $state
                    })
                    ->colors(['gray' => BonusGrant::SOURCE_ADMIN, 'warning' => BonusGrant::SOURCE_PROMO]),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        BonusGrant::STATUS_LOCKED => 'Заключен',
                        BonusGrant::STATUS_RELEASED => 'Освободен',
                        BonusGrant::STATUS_CANCELLED => 'Отменен',
                        default => $state
                    })
                    ->colors([
                        'warning' => BonusGrant::STATUS_LOCKED,
                        'success' => BonusGrant::STATUS_RELEASED,
                        'danger' => BonusGrant::STATUS_CANCELLED,
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('Начислен на')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->sortable(),
                Tables\Columns\TextColumn::make('released_at')->label('Освободен на')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')->label('Основание')->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('cancel_reason')->label('Причина за отмяна')->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    BonusGrant::STATUS_LOCKED => 'Заключен',
                    BonusGrant::STATUS_RELEASED => 'Освободен',
                    BonusGrant::STATUS_CANCELLED => 'Отменен',
                ]),
                Tables\Filters\SelectFilter::make('source')->label('Източник')->options([
                    BonusGrant::SOURCE_ADMIN => 'Ръчен',
                    BonusGrant::SOURCE_PROMO => 'Промо',
                ]),
            ])
            ->actions([
                // The only way a locked bonus leaves the books unpaid. Released
                // bonuses are real money in the investor's balance and are NOT
                // reversible from here.
                Action::make('cancel_bonus')
                    ->label('Отмени')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BonusGrant $record) => $record->isLocked())
                    ->modalHeading('Отмени заключения бонус')
                    ->modalDescription('Сумата се отписва от бонус сметката на инвеститора и не му се изплаща.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина')
                            ->required()
                            // «Отменен бонус: » prefix (16 chars) + reason must
                            // fit the VARCHAR(255) ledger description.
                            ->maxLength(230),
                    ])
                    ->requiresConfirmation()
                    ->action(function (BonusGrant $record, array $data) {
                        try {
                            $cancelled = app(BonusService::class)->cancel($record, (int) auth()->id(), trim($data['reason']));

                            if ($cancelled === null) {
                                Notification::make()->title('Бонусът вече не е заключен')
                                    ->body('Междувременно е освободен или отменен.')->warning()->send();

                                return;
                            }

                            Notification::make()->title('Бонусът е отменен')
                                ->body("{$cancelled->amount} € са отписани.")->success()->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Отмяната е отказана')->body($e->getMessage())->danger()->send();
                        } catch (\Throwable $e) {
                            Log::error('Bonus cancel failed', ['bonus_grant_id' => $record->id, 'error' => $e->getMessage()]);
                            Notification::make()->title('Грешка при отмяна на бонуса')->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBonusGrants::route('/'),
        ];
    }
}
