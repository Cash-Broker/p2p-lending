<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Notifications\EarlyRepaymentReceivedNotification;
use App\Services\Loans\EarlyRepaymentAlreadyExecutedException;
use App\Services\Loans\EarlyRepaymentCalculationService;
use App\Services\Loans\EarlyRepaymentExecutionService;
use BackedEnum;
use Closure;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Кредити';
    protected static ?string $pluralModelLabel = 'Кредити';
    protected static ?string $modelLabel = 'Кредит';
    protected static ?int $navigationSort = 4;

    /**
     * Inline borrower/co-debtor form — "general info" only. ЕГН (personal_id)
     * is intentionally absent; the client wants borrowers added on the spot
     * without it.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected static function borrowerInlineForm(): array
    {
        return [
            Forms\Components\TextInput::make('full_name')->label('Пълно име')->required(),
            Forms\Components\TextInput::make('phone')->label('Телефон')->required(),
            Forms\Components\TextInput::make('address')->label('Адрес')->required(),
            Forms\Components\TextInput::make('income')->label('Доход (€)')->numeric()->required(),
            Forms\Components\TextInput::make('credit_score')->label('Кредитен рейтинг')->numeric()->nullable(),
            Forms\Components\Textarea::make('notes')->label('Бележки')->nullable()->columnSpanFull(),
        ];
    }

    /** Persist an inline-created borrower + its investor-facing anonymized profile. */
    protected static function createBorrowerInline(array $data): int
    {
        $borrower = Borrower::create($data);
        $borrower->ensureAnonymizedProfile();

        return $borrower->getKey();
    }

    /**
     * In-memory borrower search. full_name is encrypted at rest, so a SQL LIKE
     * can't match it — we hydrate (decrypt) and filter in PHP. Inline creation
     * keeps the borrower list small, so loading a bounded set is fine.
     *
     * @return array<int, string>
     */
    protected static function searchBorrowers(string $search): array
    {
        return Borrower::query()->latest('id')->limit(300)->get()
            ->filter(fn (Borrower $borrower) => mb_stripos((string) $borrower->full_name, $search) !== false)
            ->take(50)
            ->mapWithKeys(fn (Borrower $borrower) => [$borrower->id => (string) $borrower->full_name])
            ->all();
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('Основни данни')->schema([
                Forms\Components\Select::make('originator_id')->label('Оригинатор')
                    ->options(Originator::pluck('name', 'id'))
                    ->required()->searchable()
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                // Relationship-backed so Filament renders the "+ Създай" inline
                // create button. full_name is encrypted → decrypt labels via
                // getOptionLabelFromRecordUsing and search in-memory.
                Forms\Components\Select::make('borrower_id')->label('Кредитополучател')
                    ->relationship('borrower', 'full_name')
                    ->getOptionLabelFromRecordUsing(fn (Borrower $record) => $record->full_name)
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchBorrowers($search))
                    ->preload()
                    ->required()
                    ->createOptionForm(self::borrowerInlineForm())
                    ->createOptionModalHeading('Нов кредитополучател')
                    ->createOptionUsing(fn (array $data): int => self::createBorrowerInline($data))
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\Select::make('co_borrower_id')->label('Съдлъжник')
                    ->helperText('По избор. Може да се добави на момента.')
                    ->relationship('coBorrower', 'full_name')
                    ->getOptionLabelFromRecordUsing(fn (Borrower $record) => $record->full_name)
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchBorrowers($search))
                    ->preload()
                    ->createOptionForm(self::borrowerInlineForm())
                    ->createOptionModalHeading('Нов съдлъжник')
                    ->createOptionUsing(fn (array $data): int => self::createBorrowerInline($data))
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\Select::make('type')->label('Тип')
                    ->options(['consumer' => 'Потребителски', 'business' => 'Бизнес', 'mortgage' => 'Ипотечен', 'bridge' => 'Мостов'])
                    ->required()
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\Select::make('status')->label('Статус')
                    ->options(function (?Loan $record) {
                        $allLabels = [
                            'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се',
                            'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял',
                            'default' => 'Просрочен', 'repaid' => 'Изплатен',
                            // P3-F1 fix (Phase 3 audit): bought_back was previously
                            // missing — for a `late` loan the Select would offer the
                            // untranslated key `bought_back` as an option.
                            'bought_back' => 'Изкупен обратно',
                        ];
                        if (! $record?->id) {
                            return ['draft' => 'Чернова'];
                        }
                        // Show current status + valid transitions only
                        $current = $record->status;
                        $allowed = Loan::ALLOWED_TRANSITIONS[$current] ?? [];
                        $options = [$current => $allLabels[$current] ?? $current];
                        foreach ($allowed as $status) {
                            $options[$status] = $allLabels[$status] ?? $status;
                        }
                        return $options;
                    })
                    ->default('draft')->required()
                    // Status transitions go through the dedicated row actions
                    // (Публикувай / Върни в чернова / Активирай), which run the
                    // safe state-machine logic. Editing it inline is disabled so a
                    // save can never break on a status change.
                    ->disabled(fn (?Loan $record) => $record?->id !== null)
                    ->helperText(fn (?Loan $record) => $record?->id
                        ? 'Статусът се сменя през бутоните в списъка: „Публикувай“ / „Върни в чернова“ / „Активирай“.'
                        : null),
            ])->columns(2),
            \Filament\Schemas\Components\Section::make('Финансови параметри')->schema([
                Forms\Components\TextInput::make('amount')->label('Сума на кредита (€)')->numeric()->required()->minValue(100)
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\TextInput::make('investable_amount')->label('Свободни за инвестиция (€)')
                    ->helperText('Колко от кредита се предлага на инвеститорите (може да е по-малко от сумата). Погасителният план се изчислява върху тази сума.')
                    ->numeric()->required()->minValue(50)
                    ->rules([
                        fn (Forms\Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $amount = $get('amount');
                            if (is_numeric($value) && is_numeric($amount) && bccomp((string) $value, (string) $amount, 2) > 0) {
                                $fail('„Свободни за инвестиция" не може да надвишава сумата на кредита.');
                            }
                        },
                    ])
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\TextInput::make('interest_rate')->label('Доходност (%)')
                    ->helperText('Годишната доходност, която инвеститорите получават. Използва се за изготвяне на погасителен план.')
                    ->numeric()->required()->step(0.01)->minValue(0.01)->maxValue(999.99)
                    ->rules(['numeric', 'min:0.01', 'max:999.99'])
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\TextInput::make('interest_rate_annual')->label('Лихва кредитополучател (%)')
                    ->helperText('Годишната лихва, която кредитополучателят плаща. Използва се за изчисляване на ГПР (APR). Трябва да е ≥ "Доходност" (разликата е марж на оригинатора).')
                    ->numeric()->required()->step(0.01)->minValue(0.01)->maxValue(999.99)
                    ->rules(['numeric', 'min:0.01', 'max:999.99'])
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
                Forms\Components\TextInput::make('term_months')->label('Срок (месеци)')->numeric()->required()->minValue(1)
                    ->disabled(fn (?Loan $record) => $record?->id && $record->status !== Loan::STATUS_DRAFT),
            ])->columns(2),

            // F5 — Rates summary. Read-only, edit-page only (no record on create).
            // Shows both investor + borrower rates + admin-only marge for pricing
            // visibility. Derived values — NOT form fields — so nothing is stored.
            \Filament\Schemas\Components\Section::make('Ставки')
                ->description('Преглед на текущите лихвени нива за този кредит.')
                ->schema([
                    Forms\Components\Placeholder::make('rate_investor')
                        ->label('Доходност (за инвеститор)')
                        ->content(fn (?Loan $record) => $record?->interest_rate !== null
                            ? number_format((float) $record->interest_rate, 2) . ' %'
                            : '—'),
                    Forms\Components\Placeholder::make('rate_apr')
                        ->label('ГПР / APR (за кредитополучател)')
                        ->content(fn (?Loan $record) => $record?->apr() !== null
                            ? $record->apr() . ' %'
                            : '—'),
                    Forms\Components\Placeholder::make('rate_marge')
                        ->label('Марж')
                        ->helperText('Разликата между ГПР и Доходност — оригинаторският спред. Само за вътрешен преглед, не се показва на инвеститорите.')
                        ->content(function (?Loan $record) {
                            if ($record?->interest_rate === null || $record?->interest_rate_annual === null) {
                                return '—';
                            }
                            $marge = bcsub(
                                (string) $record->interest_rate_annual,
                                (string) $record->interest_rate,
                                2,
                            );
                            return $marge . ' %';
                        }),
                ])
                ->columns(3)
                ->visible(fn (?Loan $record): bool => $record !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('originator.name')->label('Оригинатор'),
                Tables\Columns\TextColumn::make('type')->label('Тип'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('funded_amount')->label('Финансирано')->money('EUR'),
                Tables\Columns\TextColumn::make('interest_rate')->label('Доходност')->suffix('%')->sortable(),
                // F5 — ГПР (APR) column. Sortable on the underlying
                // interest_rate_annual column. Falls back to "—" for any
                // null / non-positive value (F1-L6 activation defense).
                Tables\Columns\TextColumn::make('interest_rate_annual')
                    ->label('ГПР (APR)')
                    ->formatStateUsing(fn (Loan $record) => $record->apr() !== null
                        ? $record->apr() . '%'
                        : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('term_months')->label('Срок')->suffix(' мес.'),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) { 'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се', 'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял', 'default' => 'Просрочен', 'repaid' => 'Изплатен', default => $state })
                    ->colors(['secondary' => 'draft', 'primary' => 'published', 'info' => 'funding', 'success' => fn ($state) => in_array($state, ['funded', 'active']), 'warning' => 'late', 'danger' => 'default', 'gray' => 'repaid']),
                // Maximum days_late across the loan's late schedules. Computed
                // via withMax (single sub-select per row, zero N+1). Sortable
                // so support can prioritise oldest-overdue first.
                Tables\Columns\TextColumn::make('max_days_late')
                    ->label('Дни закъснение')
                    ->getStateUsing(fn (Loan $r) => $r->amortizationSchedules()->where('status', 'late')->max('days_late'))
                    ->sortable(false)
                    ->placeholder('—')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 30 ? 'danger' : 'warning')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се',
                    'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял',
                    'default' => 'Просрочен', 'repaid' => 'Изплатен',
                ]),
                Tables\Filters\SelectFilter::make('originator_id')->label('Оригинатор')->options(Originator::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('type')->options(['consumer' => 'Потребителски', 'business' => 'Бизнес', 'mortgage' => 'Ипотечен', 'bridge' => 'Мостов']),
                // Quick toggle so support can land on "show me everything currently
                // late" without picking from the status dropdown each time.
                Tables\Filters\Filter::make('late_or_default')
                    ->label('Само закъснели/просрочени')
                    ->toggle()
                    ->query(fn ($q) => $q->whereIn('status', [Loan::STATUS_LATE, Loan::STATUS_DEFAULT])),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('publish')->label('Публикувай')->icon('heroicon-o-globe-alt')->color('success')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_DRAFT)->requiresConfirmation()
                    ->action(function (Loan $r) {
                        DB::transaction(function () use ($r) {
                            $loan = Loan::where('id', $r->id)->lockForUpdate()->firstOrFail();
                            $loan->transitionTo(Loan::STATUS_PUBLISHED);
                            $loan->forceFill(['published_at' => now()])->save();
                        });
                        Notification::make()->title('Публикуван')->success()->send();
                    }),
                \Filament\Actions\Action::make('unpublish')->label('Върни в чернова')->icon('heroicon-o-pause-circle')->color('warning')
                    ->tooltip('Скрива кредита от инвеститорите (връща го в чернова)')
                    // P3-F2 (Phase 3): extended to cover FUNDING loans too
                    // (funded_amount == 0), not just PUBLISHED. An
                    // investor whose withdrawal was rejected could leave
                    // the loan in FUNDING with funded_amount=0 — this
                    // action now unsticks it. Model-level guard re-checks
                    // funded_amount on the save.
                    ->visible(fn (Loan $r) => in_array($r->status, [Loan::STATUS_PUBLISHED, Loan::STATUS_FUNDING], true)
                        && bccomp((string) $r->funded_amount, '0', 2) <= 0)
                    ->requiresConfirmation()
                    ->action(function (Loan $r) {
                        DB::transaction(function () use ($r) {
                            $loan = Loan::where('id', $r->id)->lockForUpdate()->firstOrFail();
                            $loan->transitionTo(Loan::STATUS_DRAFT);
                            $loan->forceFill(['published_at' => null])->save();
                        });
                        Notification::make()->title('Спрян')->warning()->send();
                    }),
                \Filament\Actions\Action::make('activate')->label('Активирай')->icon('heroicon-o-play')->color('success')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_FUNDED)->requiresConfirmation()
                    ->modalDescription('Кредитът ще стане активен и ще започнат погашения.')
                    ->action(function (Loan $r) {
                        DB::transaction(function () use ($r) {
                            $loan = Loan::where('id', $r->id)->lockForUpdate()->firstOrFail();
                            $loan->transitionTo(Loan::STATUS_ACTIVE);
                        });
                        Notification::make()->title('Кредитът е активиран')->success()->send();
                    }),

                // F3 — early repayment: admin-triggered full loan close-out.
                // Visible only for mid-life statuses (active/late/default) that
                // have not yet been closed via ANY terminal path (not already
                // early-repaid, not bought-back). Fresh calc at modal open.
                //
                // Visibility intentionally does NOT query amortization_schedules
                // (per-row DB hit = N+1 on large tables). If a loan passes the
                // simple status/flag filter but has no unpaid schedules, the
                // modal's error panel surfaces that cleanly.
                \Filament\Actions\Action::make('execute_early_repayment')
                    ->label('Предсрочно погасяване')
                    ->icon('heroicon-o-forward')
                    ->color('success')
                    ->visible(fn (Loan $r) => in_array($r->status, [
                            Loan::STATUS_ACTIVE,
                            Loan::STATUS_LATE,
                            Loan::STATUS_DEFAULT,
                        ], true)
                        && $r->early_repaid_at === null
                        && $r->bought_back_at === null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record) => "Предсрочно погасяване на кредит #{$record->id}")
                    ->modalContent(function (Loan $record) {
                        // Fresh calc at modal open time — matches F2 buyback pattern.
                        // On calculator exception (no unpaid schedules, etc.) the
                        // Blade view renders an error panel instead of the breakdown.
                        try {
                            $calc = app(EarlyRepaymentCalculationService::class)
                                ->calculateTotal($record);
                            $investorCount = $record->investments()
                                ->select('user_id')
                                ->distinct()
                                ->count('user_id');

                            return view('filament.modals.early-repayment-preview', [
                                'loan'          => $record,
                                'calc'          => $calc,
                                'investorCount' => $investorCount,
                                'error'         => null,
                            ]);
                        } catch (InvalidArgumentException $e) {
                            return view('filament.modals.early-repayment-preview', [
                                'loan'          => $record,
                                'calc'          => null,
                                'investorCount' => 0,
                                'error'         => $e->getMessage(),
                            ]);
                        }
                    })
                    ->modalSubmitActionLabel('Изпълни погасяване')
                    ->action(function (Loan $record) {
                        // Triple catches — specific → generic. Order matters:
                        //   1. EarlyRepaymentAlreadyExecutedException — idempotency
                        //      hit; benign "already done" → warning toast.
                        //   2. InvalidArgumentException — wrong status / zero total /
                        //      (future: dismissed) → danger toast with service message.
                        //   3. Throwable — unexpected; log + generic danger toast.
                        try {
                            $result = app(EarlyRepaymentExecutionService::class)->execute(
                                loanId: $record->id,
                                adminId: auth()->id(),
                            );

                            // Dispatch per-investor notification AFTER the
                            // service's DB::transaction committed. Each send
                            // in its own try/catch so one bad address cannot
                            // starve the rest (F1/F2 discipline).
                            $notifiedCount = 0;
                            foreach ($result->distributions as $d) {
                                try {
                                    $investor = $d['user'] ?? User::find($d['user_id']);
                                    if (! $investor) {
                                        continue;
                                    }
                                    $investor->notify(new EarlyRepaymentReceivedNotification(
                                        loan: $record,
                                        executedAt: $result->executedAt,
                                        investorPrincipal: $d['principal'],
                                        investorInterest: $d['interest'],
                                        totalReceived: $d['total'],
                                    ));
                                    $notifiedCount++;
                                } catch (\Throwable $e) {
                                    Log::warning('Failed to send EarlyRepaymentReceivedNotification', [
                                        'loan_id' => $record->id,
                                        'user_id' => $d['user_id'],
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }

                            Notification::make()
                                ->title('Предсрочно погасяване изпълнено')
                                ->body(sprintf(
                                    'Разпределени %s € към %d %s. Кредитът е маркиран като погасен. Уведомления queued: %d.',
                                    $result->totalAmount,
                                    $result->investorCount,
                                    $result->investorCount === 1 ? 'инвеститор' : 'инвеститори',
                                    $notifiedCount,
                                ))
                                ->success()
                                ->send();
                        } catch (EarlyRepaymentAlreadyExecutedException $e) {
                            Notification::make()
                                ->title('Вече изпълнено')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Невалидна операция')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error('Early repayment execute failed unexpectedly', [
                                'loan_id'   => $record->id,
                                'admin_id'  => auth()->id(),
                                'exception' => $e::class,
                                'message'   => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Грешка')
                                ->body('Неочаквана грешка. Моля проверете логовете.')
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LoanResource\RelationManagers\AmortizationSchedulesRelationManager::class,
            LoanResource\RelationManagers\InvestmentsRelationManager::class,
            LoanResource\RelationManagers\LoanEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLoans::route('/'), 'create' => Pages\CreateLoan::route('/create'), 'edit' => Pages\EditLoan::route('/{record}/edit')];
    }
}
