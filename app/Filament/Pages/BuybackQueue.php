<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PlatformSetting;
use App\Services\Loans\BuybackAlreadyExecutedException;
use App\Services\Loans\BuybackCalculationService;
use App\Services\Loans\BuybackExecutionService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * F2 Buyback Queue — admin-facing list of loans whose originator has
 * crossed the buyback trigger threshold.
 *
 * The daily `loans:detect-buyback-eligible` cron flags loans
 * (`buyback_eligible_at` timestamp). This page surfaces the queue so the
 * admin can:
 *   1. Review each flagged loan + its calculated buyback amount.
 *   2. Execute buyback (once the originator has actually paid — verified
 *      externally, off-platform per Q1).
 *   3. Dismiss (with reason) if the buyback won't proceed yet. Dismissed
 *      items are skipped by the next cron run. Reversible via Reactivate.
 *
 * Navigation badge shows pending count (eligible, not dismissed, not
 * executed). Warning color when > 0, invisible when 0.
 *
 * Default sort: oldest buyback_eligible_at FIRST — most urgent items at top.
 *
 * Filter: by default shows PENDING only (not dismissed). Admin can toggle
 * to see dismissed or all.
 *
 * Row actions:
 *   - Execute: confirmation modal shows FRESH calculated amount (not
 *     cached at-detection), calls BuybackExecutionService::execute().
 *     Error states surface as danger toast.
 *     (TODO Step 6: dispatch LoanBoughtBackNotification per investor —
 *      currently only the Filament admin toast fires.)
 *   - Dismiss: form with required reason (≥5 chars), stamps timestamp +
 *     admin id + reason on the loan.
 *   - Reactivate: clears dismissed_at/by/reason; loan goes back into
 *     the active queue.
 */
class BuybackQueue extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Buyback Queue';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?int $navigationSort = 5;
    protected static ?string $title = 'Buyback Queue';

    protected string $view = 'filament.pages.buyback-queue';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Loan::query()
                    ->whereNotNull('buyback_eligible_at')
                    ->whereNull('bought_back_at')
                    ->with('originator')
            )
            ->defaultSort('buyback_eligible_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Кредит')
                    ->formatStateUsing(fn ($state) => "#{$state}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('originator.name')
                    ->label('Оригинатор')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'consumer' => 'Потребителски',
                        'business' => 'Бизнес',
                        'mortgage' => 'Ипотечен',
                        'bridge' => 'Мостов',
                        default => $state,
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('funded_amount')
                    ->label('Финансирано')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('became_late_at')
                    ->label('Late от')
                    ->dateTime('d.m.Y')
                    ->description(fn (Loan $record) => $record->became_late_at?->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('buyback_eligible_at')
                    ->label('Eligible от')
                    ->dateTime('d.m.Y')
                    ->description(fn (Loan $record) => $record->buyback_eligible_at?->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('coverage')
                    ->label('Покритие')
                    ->getStateUsing(fn (Loan $record) => $record->originator?->buyback_coverage
                        ?? PlatformSetting::get('buyback_default_coverage'))
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'principal_only' => 'Главница',
                        'principal_plus_interest' => '+ лихва',
                        default => '—',
                    })
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'principal_plus_interest' => 'success',
                        'principal_only' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('buyback_total_at_detection')
                    ->label('Сума (при откриване)')
                    ->getStateUsing(function (Loan $record): ?string {
                        $event = $record->events()
                            ->where('event_type', LoanEvent::TYPE_BUYBACK_TRIGGERED)
                            ->latest('id')
                            ->first();
                        return $event?->metadata['calculated_buyback_amount_at_detection'] ?? null;
                    })
                    ->formatStateUsing(fn (?string $state) => $state
                        ? number_format((float) $state, 2, ',', ' ') . ' €'
                        : '—'),

                Tables\Columns\TextColumn::make('state')
                    ->label('Състояние')
                    ->getStateUsing(fn (Loan $record) => $record->buyback_dismissed_at ? 'dismissed' : 'pending')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Чакащ',
                        'dismissed' => 'Dismissed',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'dismissed' => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('buyback_dismissed_at')
                    ->label('Филтър')
                    ->placeholder('Всички')
                    ->trueLabel('Само dismissed')
                    ->falseLabel('Само чакащи')
                    ->default(false)
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('buyback_dismissed_at'),
                        false: fn ($q) => $q->whereNull('buyback_dismissed_at'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->actions([
                Actions\Action::make('execute')
                    ->label('Execute')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Loan $record) => $record->buyback_dismissed_at === null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record) => "Изпълни buyback за кредит #{$record->id}")
                    ->modalDescription(function (Loan $record) {
                        try {
                            $calc = app(BuybackCalculationService::class)->calculateTotal($record);
                            return sprintf(
                                'Ще разпределени %s € (главница: %s €, лихва: %s €; покритие: %s) към инвеститорите на кредит #%d. Действието е необратимо.',
                                $calc->total, $calc->principal, $calc->interest,
                                $calc->coverageType, $record->id,
                            );
                        } catch (\Throwable $e) {
                            return 'Не може да изчисли buyback сумата: ' . $e->getMessage();
                        }
                    })
                    ->modalSubmitActionLabel('Изпълни buyback')
                    ->action(function (Loan $record) {
                        try {
                            $result = app(BuybackExecutionService::class)->execute(
                                loanId: $record->id,
                                adminId: auth()->id(),
                            );

                            // TODO Step 6: dispatch LoanBoughtBackNotification per investor
                            // foreach ($result->distributions as $d) {
                            //     $d['user']->notify(new LoanBoughtBackNotification(
                            //         loan: $record,
                            //         result: $result,
                            //         distribution: $d,
                            //     ));
                            // }

                            Notification::make()
                                ->title('Buyback изпълнен')
                                ->body(sprintf(
                                    'Кредит #%d: разпределени %s € (гл.: %s, л.: %s) към %d инвеститор(и).',
                                    $result->loanId,
                                    $result->totalAmount, $result->totalPrincipal, $result->totalInterest,
                                    $result->investorCount,
                                ))
                                ->success()
                                ->send();
                        } catch (BuybackAlreadyExecutedException $e) {
                            Notification::make()
                                ->title('Вече изкупен')
                                ->body($e->getMessage())
                                ->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Грешка при buyback')
                                ->body($e->getMessage())
                                ->danger()->send();
                        }
                    }),

                Actions\Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Loan $record) => $record->buyback_dismissed_at === null)
                    ->schema([
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина за dismiss')
                            ->required()
                            ->minLength(5)
                            ->maxLength(255)
                            ->placeholder('напр. "Originator ще плати в понеделник"'),
                    ])
                    ->action(function (Loan $record, array $data) {
                        $record->forceFill([
                            'buyback_dismissed_at' => now(),
                            'buyback_dismissed_reason' => $data['reason'],
                            'buyback_dismissed_by' => auth()->id(),
                        ])->save();

                        Notification::make()
                            ->title('Dismissed')
                            ->body("Кредит #{$record->id}: няма да бъде flag-нат от cron при следващ run. Reactivate за повторно разглеждане.")
                            ->success()->send();
                    }),

                Actions\Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (Loan $record) => $record->buyback_dismissed_at !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record) => "Reactivate кредит #{$record->id}")
                    ->modalDescription('Кредитът ще бъде върнат в активния queue. Cron ще го re-flagне на следващия си run ако продължава да отговаря на критериите.')
                    ->action(function (Loan $record) {
                        $record->forceFill([
                            'buyback_dismissed_at' => null,
                            'buyback_dismissed_reason' => null,
                            'buyback_dismissed_by' => null,
                        ])->save();

                        Notification::make()
                            ->title('Reactivated')
                            ->body("Кредит #{$record->id}: върнат в queue-a.")
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('Няма loans за buyback')
            ->emptyStateDescription('Ежедневната проверка още не е flag-нала нищо. Cron runs at 03:45 daily.')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    /**
     * Navigation badge — count of loans pending admin review
     * (eligible, not dismissed, not executed). Invisible when 0.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Loan::whereNotNull('buyback_eligible_at')
            ->whereNull('buyback_dismissed_at')
            ->whereNull('bought_back_at')
            ->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getNavigationBadge() ? 'warning' : null;
    }
}
