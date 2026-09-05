<?php

namespace App\Filament\Resources;

use App\Enums\PayoutType;
use App\Filament\Resources\LoanResource\Pages;
use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use App\Models\Investment;
use App\Models\InvestmentSchedule;
use App\Models\Loan;
use App\Models\Originator;
use App\Models\User;
use App\Notifications\EarlyRepaymentReceivedNotification;
use App\Notifications\LoanPartiallyClosedNotification;
use App\Services\Loans\EarlyClosureCalculationService;
use App\Services\Loans\EarlyClosureExecutionService;
use App\Services\Loans\EarlyRepaymentAlreadyExecutedException;
use App\Services\Loans\EarlyRepaymentCalculationService;
use App\Services\Loans\EarlyRepaymentExecutionService;
use App\Services\Loans\PayoutPauseService;
use App\Services\OfferProjectionService;
use App\Services\ScheduledPayoutService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Кредити';

    protected static ?string $pluralModelLabel = 'Кредити';

    protected static ?string $modelLabel = 'Кредит';

    protected static ?int $navigationSort = 4;

    /**
     * Inline borrower/co-debtor form. ЕГН (personal_id) is intentionally
     * absent; the client wants borrowers added on the spot without it.
     *
     * The «Профил за инвеститора» section feeds the ANONYMIZED profile —
     * without it the investor page showed «Неопределен» region/purpose
     * placeholders (boss complaint 2026-08-10: «това трябва да го вижда
     * инвеститорът»).
     *
     * @return array<int, Component>
     */
    protected static function borrowerInlineForm(): array
    {
        return [
            Section::make('Досие (поверително, вижда се само от админ)')->schema([
                TextInput::make('full_name')->label('Пълно име')->required(),
                TextInput::make('phone')->label('Телефон')->required(),
                TextInput::make('address')->label('Адрес')->required(),
                TextInput::make('income')->label('Доход (€)')->numeric()->required(),
                Forms\Components\Select::make('credit_score')->label('Кредитен рейтинг')
                    ->options(Borrower::CREDIT_RATINGS)
                    ->placeholder('— без рейтинг —')
                    ->nullable(),
                Forms\Components\Textarea::make('notes')->label('Бележки')->nullable()->columnSpanFull(),
            ])->columns(2),
            Section::make('Профил за инвеститора (анонимен, вижда се на сайта)')->schema([
                Forms\Components\Select::make('profile_risk_class')->label('Рисков клас')
                    ->options(['A' => 'A — Нисък', 'B' => 'B — Умерен', 'C' => 'C — Среден', 'D' => 'D — Повишен', 'E' => 'E — Висок'])
                    ->default('C')
                    ->required(),
                TextInput::make('profile_region')->label('Регион')
                    ->placeholder('напр. Кюстендил')->required(),
                Forms\Components\Select::make('profile_loan_purpose')->label('Цел на кредита')
                    ->options(BorrowerAnonymizedProfile::LOAN_PURPOSES)
                    ->required(),
                Forms\Components\Select::make('profile_collateral_type')->label('Обезпечение')
                    ->options(BorrowerAnonymizedProfile::COLLATERAL_TYPES)
                    ->placeholder('—')
                    ->nullable(),
                Forms\Components\Select::make('profile_age_group')->label('Възрастова група')
                    ->options(BorrowerAnonymizedProfile::AGE_GROUPS)
                    ->placeholder('—')
                    ->nullable(),
            ])->columns(2),
        ];
    }

    /** Persist an inline-created borrower + its investor-facing anonymized profile. */
    protected static function createBorrowerInline(array $data): int
    {
        $profile = [
            'risk_class' => $data['profile_risk_class'] ?? null,
            'region' => $data['profile_region'] ?? null,
            'loan_purpose' => $data['profile_loan_purpose'] ?? null,
            'collateral_type' => $data['profile_collateral_type'] ?? null,
            'age_group' => $data['profile_age_group'] ?? null,
        ];

        $borrower = Borrower::create(collect($data)->except([
            'profile_risk_class', 'profile_region', 'profile_loan_purpose',
            'profile_collateral_type', 'profile_age_group',
        ])->all());
        $borrower->ensureAnonymizedProfile($profile);

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

    /**
     * Reusable „Линк за инвеститор" action — used both as a table row action
     * and on the Edit page header. Generates the share token lazily on first
     * open and shows the copyable private-access URL. Visible only for private
     * loans.
     */
    public static function shareLinkAction(): Action
    {
        return Action::make('share_link')
            ->label('Линк за инвеститор')
            ->icon('heroicon-o-link')
            ->color('info')
            ->visible(fn (?Loan $record) => $record?->visibility === Loan::VISIBILITY_PRIVATE)
            ->fillForm(function (Loan $record) {
                if (empty($record->share_token)) {
                    $record->forceFill(['share_token' => Loan::generateShareToken()])->save();
                }

                return ['share_url' => url('/invest/shared/'.$record->share_token)];
            })
            ->form([
                TextInput::make('share_url')
                    ->label('Линк за частен достъп')
                    ->helperText('Копирайте линка и го изпратете на инвеститора. Кредитът трябва да е ПУБЛИКУВАН, за да е достъпен през линка (видимостта остава „Частен").')
                    ->readOnly()
                    ->columnSpanFull(),
            ])
            ->action(fn () => null)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Затвори');
    }

    /**
     * Audit 2026-09-01: a private link that leaked (forwarded mail, chat
     * screenshot) could not be withdrawn. Rotation issues a new token and drops
     * every access grant the old link handed out; investors who already hold a
     * position keep their access through the investment itself.
     */
    public static function rotateShareLinkAction(): Action
    {
        return Action::make('rotate_share_link')
            ->label('Нов линк')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (?Loan $record) => $record?->visibility === Loan::VISIBILITY_PRIVATE && ! empty($record->share_token))
            ->requiresConfirmation()
            ->modalHeading('Генериране на нов частен линк')
            ->modalDescription('Старият линк спира да работи веднага. Който го е отворил, без да инвестира, губи достъпа си до кредита, докато не получи новия. Инвеститорите с позиция в кредита запазват достъпа си.')
            ->modalSubmitActionLabel('Генерирай нов линк')
            ->action(function (Loan $record): void {
                DB::transaction(function () use ($record): void {
                    $loan = Loan::whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                    $loan->forceFill(['share_token' => Loan::generateShareToken()])->save();
                    $loan->grants()->delete();
                });
                $record->refresh();

                Notification::make()
                    ->title('Нов линк е генериран')
                    ->body('Старият линк вече не работи. Отворете «Линк за инвеститор», за да копирате новия.')
                    ->success()
                    ->send();
            });
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Основни данни')->schema([
                // Client decision 2026-08-10: EVERYTHING stays editable, even
                // with investments. The banner is the informed-edit guard —
                // committed investors keep their contracted terms regardless
                // (Investment snapshots + frozen contracts), so a term edit
                // here changes only what FUTURE investors see.
                Forms\Components\Placeholder::make('partial_funding_closed_notice')
                    ->label('Приключен без пълно финансиране')
                    ->content(fn (?Loan $record) => sprintf(
                        'Кредитът е приключен на %s, без да достигне пълно финансиране (%s от %s €): всички инвеститори са изплатени по своите планове. Не приема нови инвестиции.',
                        $record?->closed_at?->format('d.m.Y') ?? '—',
                        number_format((float) ($record?->funded_amount ?? 0), 2, ',', ' '),
                        number_format((float) ($record?->investableAmount() ?? 0), 2, ',', ' '),
                    ))
                    ->visible(fn (?Loan $record): bool => (bool) $record?->wasClosedWithoutFullFunding())
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('invested_edit_warning')
                    ->label('⚠ Внимание')
                    ->content('Кредитът вече има инвестиции. Промените по сума/лихва/срок НЕ променят договорите и графиците на вече инвестиралите (те остават при условията, при които са влезли) — отразяват се само към бъдещи инвеститори и визуализацията на кредита.')
                    ->visible(fn (?Loan $record) => $record?->id && ! $record->isTermsEditable())
                    ->columnSpanFull(),
                Forms\Components\Select::make('originator_id')->label('Оригинатор')
                    ->options(Originator::pluck('name', 'id'))
                    ->required()->searchable()
                    ->validatedWhenNotDehydrated(false),
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
                    ->validatedWhenNotDehydrated(false),
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
                    ->validatedWhenNotDehydrated(false),
                // Hand-entered by the admin — matches the REAL credit-contract
                // paperwork number (boss 2026-08-10), never auto-generated.
                TextInput::make('contract_number')->label('Номер на договор')
                    ->placeholder('напр. 1042/2026')
                    ->maxLength(64)
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Вече има кредит с този номер на договор.'])
                    ->helperText('Реалният номер на договора за кредита. Празно = показва се само системният #.'),
                Forms\Components\Select::make('type')->label('Тип')
                    ->options(['consumer' => 'Потребителски', 'business' => 'Бизнес', 'mortgage' => 'Ипотечен', 'bridge' => 'Мостов'])
                    ->required()
                    ->validatedWhenNotDehydrated(false),
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
                        // Current status (no-op default) + the transitions the
                        // admin may set BY HAND. Money-bearing terminals
                        // (repaid / bought_back) and funded → active are
                        // excluded — they must run through their payout-
                        // performing actions so investor capital is actually
                        // moved, not just relabelled. See
                        // Loan::selectableStatusTransitions().
                        $current = $record->status;
                        $options = [$current => $allLabels[$current] ?? $current];
                        foreach ($record->selectableStatusTransitions() as $status) {
                            $options[$status] = $allLabels[$status] ?? $status;
                        }

                        return $options;
                    })
                    ->default('draft')->required(),
                // Payout trigger mode. Operational, NOT a frozen financial term —
                // stays editable after draft so an admin can switch already
                // uploaded loans (no status-gated disabled()).
                Forms\Components\Select::make('payout_mode')->label('Изплащане')
                    ->helperText('Автоматично — системата начислява вноските на падеж сама. Ръчно — админът ги пуска с бутон.')
                    ->options([
                        Loan::PAYOUT_MODE_MANUAL => 'Ръчно (админ пуска)',
                        Loan::PAYOUT_MODE_AUTOMATIC => 'Автоматично (по график)',
                    ])
                    ->default(Loan::PAYOUT_MODE_MANUAL)
                    ->required(),
                // PAY-13: read-only state of the payout pause. NO per-loan exempt
                // toggle in v1 (open question) — the engine is the braces.
                Forms\Components\Placeholder::make('payout_pause_state')
                    ->label('Авансиране')
                    ->visible(fn (?Loan $record) => (bool) $record?->isPayoutPaused())
                    ->content(fn (?Loan $record) => 'СПРЯНО от '.$record?->payouts_paused_at?->format('d.m.Y').' — кредитополучателят е в закъснение над прага от Настройки. Възобновява се автоматично при следващото нощно плащане, след като вноските бъдат отбелязани като платени в таб „Погасителен план“, при изкупуване, или ако настройката payout_pause_enabled бъде изключена.'),
                // Private loans are hidden from the public board and reachable
                // only via their share link. Freely editable (not a financial
                // term, so not frozen post-draft).
                Forms\Components\Select::make('visibility')->label('Видимост')
                    ->options([
                        Loan::VISIBILITY_PUBLIC => 'Публичен (на таблото)',
                        Loan::VISIBILITY_PRIVATE => 'Частен (само с линк)',
                    ])
                    ->default(Loan::VISIBILITY_PUBLIC)
                    ->required()
                    ->helperText('Частните кредити не се виждат на общото табло — достъп само през линк.'),
            ])->columns(2),
            Section::make('Финансови параметри')->schema([
                TextInput::make('amount')->label('Сума на кредита (€)')->numeric()->required()->minValue(100)
                    ->validatedWhenNotDehydrated(false),
                // Renamed from «Свободни за инвестиция» (boss 2026-08-10):
                // that name now belongs to the LIVE remaining figure below —
                // this input is the total offered to investors, a set-once cap.
                TextInput::make('investable_amount')->label('Предлагани на инвеститорите (€)')
                    ->helperText('Общо колко от кредита се предлага на инвеститорите (може да е по-малко от сумата). Празно = цялата сума. Оставащото се смята само — вижте „Текущо състояние“.')
                    // Required on CREATE; legacy loans legitimately store NULL
                    // (= fall back to the full amount), so an edit of an
                    // unlocked loan must not be blocked by an empty value.
                    ->numeric()->required(fn (?Loan $record) => $record === null)->minValue(50)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $amount = $get('amount');
                            if (is_numeric($value) && is_numeric($amount) && bccomp((string) $value, (string) $amount, 2) > 0) {
                                $fail('„Свободни за инвестиция" не може да надвишава сумата на кредита.');
                            }
                        },
                    ])
                    ->validatedWhenNotDehydrated(false),
                // «Доходност (%)» and «Лихва кредитополучател (%)» removed
                // from the form (boss 2026-08-10: «ненужни са — трите оферти
                // долу са достатъчни»). The columns stay nullable for legacy
                // loans; the admin-only ГПР/Марж preview went with them.
                // Audit 2026-09-01 (PAY-40): the annuity generators truncate per
                // row and the last row absorbs the drift — for long terms and
                // small principals the drift exceeds the balance and the
                // projection refuses. No fixed cap (the term is the client's to
                // set); the pair is checked against the 50 € minimum investment
                // for every amortizing rate the loan carries.
                TextInput::make('term_months')->label('Срок (месеци)')->numeric()->required()->minValue(1)
                    ->rules([
                        fn (?Loan $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            self::assertTermAmortizes((int) $value, $record, $fail);
                        },
                    ])
                    ->validatedWhenNotDehydrated(false),
                // Live funding math, in the boss's exact vocabulary
                // (2026-08-10): «Сума на кредита 13 000 · Инвестирани 5 000 ·
                // Свободни за инвестиране 6 200 — с всяка инвестиция тези
                // числа се променят». Three stat cards (blade partial) —
                // recomputed from the DB on every page load, never typed.
                Forms\Components\Placeholder::make('funding_progress')
                    ->label('Текущо състояние')
                    ->content(function (?Loan $record): HtmlString|string {
                        if (! $record?->id) {
                            return '—';
                        }
                        $remaining = bcsub($record->fundingCap(), (string) $record->funded_amount, 2);
                        if (bccomp($remaining, '0', 2) < 0) {
                            $remaining = '0.00';
                        }
                        $format = fn (string $v) => number_format((float) $v, 2, ',', ' ');
                        // PAY-30: a loan that closed without full funding must not
                        // advertise cap − funded as «свободни».
                        $closed = $record->wasClosedWithoutFullFunding();
                        if ($closed) {
                            $remaining = '0.00';
                        }

                        return new HtmlString(view('filament.components.loan-funding-stats', [
                            'amount' => $format((string) $record->amount),
                            'invested' => $format((string) $record->funded_amount),
                            'remaining' => $format($remaining),
                            'closed' => $closed,
                        ])->render());
                    })
                    ->visible(fn (?Loan $record): bool => (bool) $record?->id)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('contract_number')->label('Договор №')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('originator.name')->label('Оригинатор'),
                Tables\Columns\TextColumn::make('type')->label('Тип'),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('funded_amount')->label('Финансирано')->money('EUR'),
                Tables\Columns\TextColumn::make('interest_rate')->label('Доходност')->suffix('%')->placeholder('—')->sortable(),
                // F5 — ГПР (APR) column. Sortable on the underlying
                // interest_rate_annual column. Falls back to "—" for any
                // null / non-positive value (F1-L6 activation defense).
                Tables\Columns\TextColumn::make('interest_rate_annual')
                    ->label('ГПР (APR)')
                    ->formatStateUsing(fn (Loan $record) => $record->apr() !== null
                        ? $record->apr().'%'
                        : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('term_months')->label('Срок')->suffix(' мес.'),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->description(fn (Loan $record) => $record->wasClosedWithoutFullFunding() ? 'частично финансиран' : null)
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Чернова', 'published' => 'Публикуван', 'funding' => 'Финансира се', 'funded' => 'Финансиран', 'active' => 'Активен', 'late' => 'Закъснял', 'default' => 'Просрочен', 'repaid' => 'Изплатен', default => $state
                    })
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
                // PAY-13: payout pause stamp (the icon shows the stamp; the tooltip
                // says whether the setting actually enforces it).
                Tables\Columns\IconColumn::make('payouts_paused_at')
                    ->label('Авансиране')
                    ->boolean()
                    ->trueIcon('heroicon-o-pause-circle')
                    ->falseIcon('heroicon-o-play-circle')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (Loan $r) => $r->payouts_paused_at
                        ? 'Спряно от '.$r->payouts_paused_at->format('d.m.Y').(PayoutPauseService::isEnabled() ? '' : ' (настройката е изключена — плаща се)')
                        : 'По график')
                    ->toggleable(),
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
                Tables\Filters\Filter::make('payouts_paused')
                    ->label('Само със спряно авансиране')
                    ->toggle()
                    ->query(fn ($q) => $q->whereNotNull('payouts_paused_at')),
            ])
            ->actions([
                EditAction::make(),
                // Private-loan share link — generates the token on first open
                // and shows the copyable URL to send to the investor.
                self::shareLinkAction(),
                self::rotateShareLinkAction(),
                Action::make('publish')->label('Публикувай')->icon('heroicon-o-globe-alt')->color('success')
                    ->visible(fn (Loan $r) => $r->status === Loan::STATUS_DRAFT)->requiresConfirmation()
                    ->action(function (Loan $r) {
                        DB::transaction(function () use ($r) {
                            $loan = Loan::where('id', $r->id)->lockForUpdate()->firstOrFail();
                            $loan->transitionTo(Loan::STATUS_PUBLISHED);
                            $loan->forceFill(['published_at' => now()])->save();
                        });
                        Notification::make()->title('Публикуван')->success()->send();
                    }),
                Action::make('unpublish')->label('Върни в чернова')->icon('heroicon-o-pause-circle')->color('warning')
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
                // «Активирай» REMOVED (client decision 2026-08-13, Reni): a
                // fully funded loan now starts repaying on its own, counted
                // from the funding date. See InvestmentService::invest().
                // There is deliberately no manual activation left — funding
                // IS the activation, so `funded` is a state no loan rests in.

                // 3-offer feature — pay investors the installments currently due
                // on their per-investment schedules (each per their chosen offer).
                // Legacy loans use the standard ProcessRepayment flow instead.
                Action::make('disburse_offers')
                    ->label('Пусни плащане сега')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Loan $r) => in_array($r->status, Loan::PAYOUT_ELIGIBLE_STATUSES, true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Loan $record) => "Изплащане към инвеститорите за кредит #{$record->id}")
                    ->modalDescription('Начислява/освобождава всички дължими към момента суми по плановете на инвеститорите. Същото действие, което авто-режимът прави сам на падеж — тук го пускате ръчно.')
                    ->action(function (Loan $record) {
                        try {
                            $result = app(ScheduledPayoutService::class)->runForLoan($record);

                            // PAY-13: the engine refused under its lock — the toast only explains.
                            if (($result['paused'] ?? false) === true) {
                                Notification::make()
                                    ->title('Авансирането е спряно')
                                    ->body('Кредитополучателят е в закъснение над прага. Отбележете платените вноски в „Погасителен план“ — плащането се възобновява автоматично при следващото нощно изпълнение.')
                                    ->danger()->send();

                                return;
                            }

                            $moved = ($result['type'] ?? null) === 'legacy'
                                ? (int) ($result['posted_count'] ?? 0)
                                : (int) ($result['released_count'] ?? 0) + (int) ($result['accrued_count'] ?? 0);

                            if ($moved === 0) {
                                Notification::make()->title('Няма дължими суми за момента')->info()->send();

                                return;
                            }

                            Notification::make()
                                ->title('Изплащането е извършено')
                                ->body('Дължимите суми по плановете на инвеститорите са обработени.')
                                ->success()->send();
                        } catch (\Throwable $e) {
                            Log::error('Scheduled payout (manual) failed', ['loan_id' => $record->id, 'error' => $e->getMessage()]);
                            Notification::make()->title('Грешка при изплащане')->body($e->getMessage())->danger()->send();
                        }
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
                Action::make('execute_early_repayment')
                    ->label('Предсрочно погасяване')
                    ->icon('heroicon-o-forward')
                    ->color('success')
                    // Legacy (per-loan amortization) loans only — offer-based
                    // ones are served by the two closure actions below, which
                    // understand per-investor schedules and partial amounts.
                    ->visible(fn (Loan $r) => in_array($r->status, [
                        Loan::STATUS_ACTIVE,
                        Loan::STATUS_LATE,
                        Loan::STATUS_DEFAULT,
                    ], true)
                        && ! $r->usesOffers()
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
                                'loan' => $record,
                                'calc' => $calc,
                                'investorCount' => $investorCount,
                                'error' => null,
                            ]);
                        } catch (InvalidArgumentException $e) {
                            return view('filament.modals.early-repayment-preview', [
                                'loan' => $record,
                                'calc' => null,
                                'investorCount' => 0,
                                'error' => $e->getMessage(),
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
                                'loan_id' => $record->id,
                                'admin_id' => auth()->id(),
                                'exception' => $e::class,
                                'message' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Грешка')
                                ->body('Неочаквана грешка. Моля проверете логовете.')
                                ->danger()
                                ->send();
                        }
                    }),

                self::earlyClosureAction(),
                self::partialClosureAction(),

                // Deletion straight from the list (boss 2026-08-10). Same
                // guards as the EditLoan header delete: ONLY drafts with zero
                // funding and no investments — a loan investors have money in
                // is a financial record, not a row to clean up (removing it
                // needs the refund/reversal flow — open product decision).
                DeleteAction::make()
                    ->label('Изтрий')
                    ->visible(fn (Loan $r) => self::isDeletableLoan($r))
                    ->before(function (Loan $record, DeleteAction $action) {
                        if (! self::isDeletableLoan($record)) {
                            Notification::make()->title('Кредитът не може да бъде изтрит')
                                ->body('Само чернови без финансиране и без инвестиции могат да се трият.')
                                ->danger()->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                // Bulk cleanup with the SAME eligibility rule per record:
                // eligible rows are deleted, the rest are skipped and counted —
                // never a silent partial wipe.
                BulkAction::make('delete_drafts')
                    ->label('Изтрий избраните (само чернови)')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Изтриване на кредити')
                    ->modalDescription('Ще бъдат изтрити САМО кредити в чернова, без финансиране и без инвестиции. Всички останали избрани ще бъдат пропуснати.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $deleted = 0;
                        $skipped = 0;

                        foreach ($records as $loan) {
                            if (! self::isDeletableLoan($loan)) {
                                $skipped++;

                                continue;
                            }

                            try {
                                $loan->delete();
                                $deleted++;
                            } catch (\Throwable $e) {
                                Log::warning('Bulk loan delete skipped a row', [
                                    'loan_id' => $loan->id, 'error' => $e->getMessage(),
                                ]);
                                $skipped++;
                            }
                        }

                        Notification::make()
                            ->title("Изтрити: {$deleted} · Пропуснати: {$skipped}")
                            ->body($skipped > 0
                                ? 'Пропуснатите не са чернови, имат финансиране или инвестиции.'
                                : 'Всички избрани кредити бяха изтрити.')
                            ->{$deleted > 0 ? 'success' : 'warning'}()
                            ->send();
                    }),
            ]);
    }

    /**
     * The single deletion rule for loans: draft + zero funded + zero
     * investments. Everything past that point is a financial record.
     */
    protected static function isDeletableLoan(Loan $loan): bool
    {
        return $loan->status === Loan::STATUS_DRAFT
            && bccomp((string) $loan->funded_amount, '0', 2) <= 0
            && ! Investment::where('loan_id', $loan->id)->exists();
    }

    public static function getRelations(): array
    {
        return [
            LoanResource\RelationManagers\OffersRelationManager::class,
            LoanResource\RelationManagers\AmortizationSchedulesRelationManager::class,
            LoanResource\RelationManagers\InvestmentsRelationManager::class,
            LoanResource\RelationManagers\LoanEventsRelationManager::class,
        ];
    }

    /**
     * Loans the early-closure buttons apply to: offer-based, still running,
     * not already finished by another path.
     */
    public static function isEarlyClosable(Loan $loan): bool
    {
        return $loan->usesOffers()
            && in_array($loan->status, [Loan::STATUS_FUNDING, Loan::STATUS_ACTIVE, Loan::STATUS_LATE, Loan::STATUS_DEFAULT], true)
            && $loan->early_repaid_at === null
            && $loan->bought_back_at === null;
    }

    /**
     * «Предсрочно погасяване» for offer-based loans (Reni 2026-08-18).
     *
     * The borrower closed the whole loan ahead of plan: every investor gets
     * their outstanding principal back plus the interest earned up to the
     * chosen day (30/360), the remaining installments are cancelled and the
     * loan lands in «Погасен».
     */
    public static function earlyClosureAction(): Action
    {
        return Action::make('early_closure')
            ->label('Предсрочно погасяване')
            ->icon('heroicon-o-forward')
            ->color('success')
            ->visible(fn (Loan $record) => self::isEarlyClosable($record))
            ->modalHeading(fn (Loan $record) => "Предсрочно погасяване на кредит #{$record->id}")
            ->modalDescription('Връща цялата остатъчна главница на инвеститорите заедно с лихвата за реално ползвания период.')
            ->form([
                DatePicker::make('as_of')
                    ->label('Лихва към дата')
                    ->default(now()->toDateString())
                    ->maxDate(now()->addDay())
                    ->required()
                    ->live(),
                // One token per opened modal — a double click replays it and
                // the service refuses the second run (audit 2026-09-01, PAY-04).
                Hidden::make('request_token')->default(fn () => (string) Str::uuid()),
            ])
            ->modalContent(fn (Loan $record, array $arguments) => self::closurePreview($record, null, null))
            ->modalSubmitActionLabel('Изпълни погасяване')
            ->action(fn (Loan $record, array $data) => self::runClosure($record, null, $data['as_of'] ?? null, $data['request_token'] ?? null));
    }

    /**
     * «Частично погасяване» — the borrower returned only part of the principal
     * («има клиенти които може предсрочно да закрият една част»). Each
     * investor's position shrinks by the same share; the term does not move.
     */
    public static function partialClosureAction(): Action
    {
        return Action::make('partial_closure')
            ->label('Частично погасяване')
            ->icon('heroicon-o-scissors')
            ->color('warning')
            ->visible(fn (Loan $record) => self::isEarlyClosable($record))
            ->modalHeading(fn (Loan $record) => "Частично погасяване на кредит #{$record->id}")
            ->modalDescription('Затваря съответния дял от позицията на ВСЕКИ инвеститор пропорционално. Броят вноски и падежите остават — намалява се размерът им.')
            ->form([
                TextInput::make('amount')
                    ->label('Върната главница (€)')
                    ->helperText(fn (Loan $record) => 'Остатъчна главница по кредита: '
                        .self::outstandingPrincipal($record).' €')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->rules(['decimal:0,2']),
                DatePicker::make('as_of')
                    ->label('Лихва към дата')
                    ->default(now()->toDateString())
                    ->maxDate(now()->addDay())
                    ->required(),
                Hidden::make('request_token')->default(fn () => (string) Str::uuid()),
            ])
            ->modalSubmitActionLabel('Изпълни погасяване')
            ->action(fn (Loan $record, array $data) => self::runClosure($record, $data['amount'], $data['as_of'] ?? null, $data['request_token'] ?? null));
    }

    /** Outstanding investor principal, for the form helper text. */
    /**
     * PAY-40 (audit 2026-09-01): refuse a term/rate pair whose annuity cannot
     * amortize the 50 € minimum investment — the per-row truncation drift
     * would otherwise surface as a negative last installment at quote or
     * invest time. Checked for every amortizing rate the loan carries (the
     * default 12 % on a new loan, whose offers are seeded on create).
     */
    public static function assertTermAmortizes(int $term, ?Loan $loan, Closure $fail): void
    {
        if ($term < 1) {
            return;
        }

        $rates = $loan?->offers()
            ->where('payout_type', PayoutType::Amortizing)
            ->pluck('interest_rate')
            ->map(fn ($rate) => (string) $rate)
            ->all();
        if (empty($rates)) {
            $rates = [PayoutType::Amortizing->defaultRate()];
        }

        foreach ($rates as $rate) {
            try {
                app(OfferProjectionService::class)->schedule('50.00', $rate, $term, PayoutType::Amortizing);
            } catch (InvalidArgumentException) {
                $fail("При срок {$term} мес. и доходност {$rate}% минималната инвестиция от 50 € не може да бъде амортизирана (месечната вноска не покрива лихвата). Намалете срока.");

                return;
            }
        }
    }

    public static function outstandingPrincipal(Loan $loan): string
    {
        return InvestmentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'late'])
            ->get(['principal'])
            ->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->principal, 2), '0.00');
    }

    /** Fresh quote rendered into the confirmation modal — never cached. */
    protected static function closurePreview(Loan $loan, ?string $amount, ?string $asOf)
    {
        $asOfDate = $asOf ? Carbon::parse($asOf) : now();

        try {
            $quote = app(EarlyClosureCalculationService::class)->quote($loan, $amount, $asOfDate);

            return view('filament.modals.early-closure-preview', [
                'loan' => $loan,
                'quote' => $quote,
                'asOf' => $asOfDate,
                'error' => null,
            ]);
        } catch (InvalidArgumentException $e) {
            return view('filament.modals.early-closure-preview', [
                'loan' => $loan,
                'quote' => null,
                'asOf' => $asOfDate,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Shared execution for both closure buttons: money first, then one
     * notification per investor, each isolated so a single bad address cannot
     * starve the rest (F1/F2/F3 discipline). Catch order specific → generic.
     */
    protected static function runClosure(Loan $record, ?string $amount, ?string $asOf, ?string $requestToken = null): void
    {
        try {
            $result = app(EarlyClosureExecutionService::class)->execute(
                loanId: $record->id,
                adminId: (int) auth()->id(),
                principalAmount: $amount,
                asOf: $asOf ? Carbon::parse($asOf) : null,
                requestToken: $requestToken,
            );

            $quote = $result['quote'];
            $closure = $result['closure'];
            $notified = 0;

            foreach ($quote['positions'] as $position) {
                try {
                    $investor = $position['investment']->user ?? User::find($position['user_id']);
                    if (! $investor) {
                        continue;
                    }

                    $investor->notify($quote['is_full']
                        ? new EarlyRepaymentReceivedNotification(
                            loan: $record->fresh(),
                            executedAt: $record->fresh()->early_repaid_at ?? now(),
                            investorPrincipal: $position['principal'],
                            investorInterest: $position['interest'],
                            totalReceived: $position['total'],
                        )
                        : new LoanPartiallyClosedNotification(
                            loanId: $record->id,
                            closureId: $closure->id,
                            principal: $position['principal'],
                            interest: $position['interest'],
                            total: $position['total'],
                            asOf: $closure->as_of,
                        ));
                    $notified++;
                } catch (\Throwable $e) {
                    Log::error('Early closure notification failed', [
                        'loan_id' => $record->id,
                        'user_id' => $position['user_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Notification::make()
                ->title($quote['is_full'] ? 'Кредитът е погасен предсрочно' : 'Частичното погасяване е изпълнено')
                ->body(sprintf(
                    'Върнати %s € главница + %s € лихва към %d %s. Уведомления: %d.',
                    $quote['principal_total'],
                    $quote['interest_total'],
                    count($quote['positions']),
                    count($quote['positions']) === 1 ? 'инвеститор' : 'инвеститори',
                    $notified,
                ))
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Невалидна операция')->body($e->getMessage())->danger()->send();
        } catch (\Throwable $e) {
            Log::error('Early closure failed unexpectedly', [
                'loan_id' => $record->id,
                'admin_id' => auth()->id(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Грешка')
                ->body('Неочаквана грешка. Моля проверете логовете.')
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLoans::route('/'), 'create' => Pages\CreateLoan::route('/create'), 'edit' => Pages\EditLoan::route('/{record}/edit')];
    }
}
