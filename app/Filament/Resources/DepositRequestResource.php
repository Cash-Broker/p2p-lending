<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepositRequestResource\Pages;
use App\Models\DepositRequest;
use App\Models\User;
use App\Services\DepositService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use UnitEnum;

class DepositRequestResource extends Resource
{
    protected static ?string $model = DepositRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Депозити';

    protected static string|UnitEnum|null $navigationGroup = 'Финанси';

    protected static ?string $pluralModelLabel = 'Депозити';

    protected static ?string $modelLabel = 'Депозит';

    protected static ?int $navigationSort = 2;

    /**
     * Wall-clock timezone for the dates in this list.
     *
     * Prod's app tz is UTC (APP_TIMEZONE is not set), so the raw timestamps
     * render two/three hours behind the hour the admin actually approved the
     * deposit. The column is about "кога стана" — it must show Sofia time.
     * Same idiom as ActivityStats / UserResource.
     */
    private const DISPLAY_TIMEZONE = 'Europe/Sofia';

    /**
     * The Filament page does NOT expose a Resource-level create form anymore.
     * Deposits are created on the user side (via /api/deposit issuing a
     * DEP-XXXXXXXX placeholder) and credited from the table-header action
     * "Захрани сметка" below, which validates the user-paste reference code.
     *
     * Disabling create here prevents accidental orphan rows.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Investor picker for the bonus grant: empty search (the preload) shows
     * the 50 newest investors; typing filters by name/email prefix or
     * fragment straight in SQL — users.name/email are plain columns.
     *
     * @return array<int, string>
     */
    protected static function searchInvestorsForBonus(string $search): array
    {
        return User::where('role', 'investor')
            ->when(trim($search) !== '', function ($query) use ($search) {
                $term = trim($search);
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * Order the list by the moment each row actually happened: `confirmed_at`
     * for an approved deposit (when the money hit the wallet), the request
     * date for everything else.
     *
     * Why not `created_at`: a DEP code is issued when the investor first opens
     * the deposit page and stays valid until it is consumed (client decision
     * 2026-07-17, no expiry) — so a code minted in June can be credited in
     * August. Sorting by issuance date scattered the approvals across the list
     * (Reni 2026-08-18).
     *
     * Raw expression by necessity (COALESCE over two columns); no user input
     * reaches it — the column names are literals and $direction is whitelisted
     * here. Filament appends its own `order by id` afterwards, which keeps
     * pagination stable for approvals that share a second.
     */
    protected static function orderByDecisionDate(Builder $query, string $direction): Builder
    {
        return $query->orderByRaw(
            'COALESCE(deposit_requests.confirmed_at, deposit_requests.created_at) '
            .($direction === 'asc' ? 'asc' : 'desc')
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR')->placeholder('—'),
                Tables\Columns\TextColumn::make('reference_code')->label('Код')->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('bank_reference')->label('Bank ref')
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Чакащ', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state
                    })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('admin_note')->label('Бележка')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                // «Дата» = кога е ОДОБРЕН депозитът (confirmed_at — моментът,
                // в който парите влизат в сметката), не кога е издаден кодът.
                // Чакащите/отхвърлените нямат такъв момент и падат обратно към
                // датата на заявката; подтекстът казва коя от двете се вижда,
                // за да не смесва една колона два смисъла безмълвно.
                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Дата')
                    ->state(fn (DepositRequest $record) => $record->confirmed_at ?? $record->created_at)
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->description(fn (DepositRequest $record) => $record->confirmed_at ? 'одобрен' : 'заявен')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::orderByDecisionDate($query, $direction)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Заявен на')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query, string $direction): Builder => self::orderByDecisionDate($query, $direction), 'desc')
            // Hide unfunded placeholder rows (amount=null, status=pending) by
            // default — they're issued codes the user hasn't wired against yet.
            // Toggle the filter off to see them (debug / orphan code cleanup).
            ->modifyQueryUsing(fn ($query) => $query->whereNotNull('amount'))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(['pending' => 'Чакащ', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен']),
            ])
            ->headerActions([
                // "Захрани сметка" — admin pastes the reference code from
                // the bank statement, system resolves the user automatically,
                // admin enters the wire amount + bank reference, system credits.
                //
                // Replaces the old free-text user-search flow that required
                // admin to manually correlate sender name → registered user
                // (audit C2 attack vector). Now the code does the matching.
                Action::make('credit_account')
                    ->label('Захрани сметка')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading('Захрани сметка')
                    ->modalDescription('Въведи reference кода от банковия превод. Системата ще намери автоматично потребителя.')
                    ->form([
                        Forms\Components\TextInput::make('reference_code')
                            ->label('Reference код от банковия превод')
                            ->placeholder('DEP-AB12CD34')
                            ->required()
                            ->live(debounce: 400)
                            ->rules(['regex:/^DEP-[A-Z0-9]{8}$/i'])
                            ->validationMessages(['regex' => 'Кодът трябва да е във формат DEP-XXXXXXXX (8 знака букви/цифри).'])
                            // Normalise to uppercase so admin's case typos
                            // ("dep-ab12cd34") still match the stored code.
                            ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state ?? ''))),
                        Forms\Components\Placeholder::make('resolved_user')
                            ->label('Засечен потребител')
                            ->content(function (callable $get) {
                                $code = strtoupper(trim((string) $get('reference_code')));
                                if ($code === '' || ! preg_match('/^DEP-[A-Z0-9]{8}$/', $code)) {
                                    return '—';
                                }
                                $deposit = DepositRequest::where('reference_code', $code)
                                    ->where('status', 'pending')
                                    ->with('user')
                                    ->first();
                                if (! $deposit) {
                                    return '⚠️ Кодът не съществува или вече е обработен.';
                                }
                                // Legacy pending codes of GDPR-deleted accounts
                                // (wallet hard-deleted) — approve() will refuse;
                                // warn upfront instead of a late error.
                                if (! $deposit->user->wallet) {
                                    return "⚠️ Акаунтът на {$deposit->user->name} е закрит — кодът не може да бъде кредитиран.";
                                }

                                return "✓ {$deposit->user->name} ({$deposit->user->email})";
                            }),
                        Forms\Components\TextInput::make('amount')
                            ->label('Сума по bank statement (€)')
                            ->numeric()->required()->minValue(1)->step(0.01)
                            ->maxValue(Money::MAX),
                        Forms\Components\TextInput::make('bank_reference')
                            ->label('Bank reference (от statement-а)')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Уникален идентификатор на превода в банковото извлечение. Същият превод не може да се credit-не два пъти.'),
                    ])
                    ->action(function (array $data) {
                        $code = strtoupper(trim($data['reference_code']));

                        // Non-locking resolve, for friendly errors only. The
                        // authoritative guard is inside DepositService::approve:
                        // row lock + status=pending recheck, with the wire
                        // details stamped in the SAME transaction — a Filament
                        // action closure runs in autocommit, so a lock taken
                        // here would be released per-statement and serialize
                        // nothing.
                        $deposit = DepositRequest::where('reference_code', $code)
                            ->where('status', 'pending')
                            ->first();

                        if (! $deposit) {
                            Notification::make()->title('Невалиден код')
                                ->body("Код {$code} не съществува или вече е обработен.")
                                ->danger()->send();

                            return;
                        }

                        // No expiry check: a pending code is valid until it's
                        // consumed here — the wire it references is already
                        // real money on the bank statement (client decision
                        // 2026-07-17).

                        // Pre-flight bank_reference duplicate check — gives
                        // a friendly error before the DB UNIQUE constraint
                        // fires. The constraint is the source of truth.
                        $duplicate = DepositRequest::where('bank_reference', $data['bank_reference'])
                            ->where('id', '!=', $deposit->id)
                            ->exists();
                        if ($duplicate) {
                            Notification::make()->title('Дублиран bank reference')
                                ->body("Bank reference '{$data['bank_reference']}' вече е използван за друг депозит.")
                                ->danger()->send();

                            return;
                        }

                        try {
                            // bcmath-safe ingress — never float-cast user input.
                            $amount = Money::normalizePositive($data['amount']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Невалидна сума')
                                ->body($e->getMessage())->danger()->send();

                            return;
                        }

                        try {
                            $deposit = app(DepositService::class)->approve(
                                $deposit->id,
                                auth()->id(),
                                $amount,
                                $data['bank_reference'],
                            );
                            // Report the CREDITED amount from the model, not
                            // the raw form input — they must never diverge.
                            Notification::make()
                                ->title("Сметката на {$deposit->user->name} е захранена с {$deposit->amount} €")
                                ->success()->send();
                        } catch (ModelNotFoundException) {
                            // Another admin consumed the code between our
                            // resolve above and the locked recheck.
                            Notification::make()->title('Кодът вече е обработен')
                                ->body("Код {$code} беше обработен от друг администратор междувременно. Провери списъка с депозити.")
                                ->danger()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title('Грешка при credit')
                                ->body($e->getMessage())->danger()->send();
                        } catch (\Throwable $e) {
                            Log::error('credit_account action failed', ['code' => $code, 'error' => $e->getMessage()]);
                            Notification::make()->title('Грешка')
                                ->body('Неочаквана грешка при захранване. Депозитът НЕ е кредитиран — провери лога.')
                                ->danger()->send();
                        }
                    }),
                // «Начисли бонус» — next to «Захрани сметка» so Reni finds it
                // in her deposit workflow, but WITHOUT a code (boss 2026-08-10:
                // «като цъкне бонуса автоматично да се зареждат всички
                // потребители и да си го търси по първите букви»): a
                // searchable investor picker — the newest 50 load upfront,
                // typing filters by name/email server-side (users.name/email
                // are plain columns, so this scales unlike borrower search).
                // The money lands as a TYPE_BONUS ledger row, never as a
                // deposit (no wire behind it).
                Action::make('grant_bonus')
                    ->label('Начисли бонус')
                    ->icon('heroicon-o-gift')
                    ->color('warning')
                    ->modalHeading('Начисли бонус')
                    ->modalDescription('Избери потребител — пиши първите букви от името или имейла. Сумата се записва като „Бонус“, не като депозит (без банков превод зад нея).')
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('Потребител')
                            ->required()
                            ->searchable()
                            // preload() fills the INITIAL list from options();
                            // getSearchResultsUsing alone leaves the dropdown
                            // empty until the admin types (the 2026-08-10 bug).
                            ->options(fn (): array => self::searchInvestorsForBonus(''))
                            ->preload()
                            ->getSearchResultsUsing(fn (string $search): array => self::searchInvestorsForBonus($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                $user = User::find($value);

                                return $user ? "{$user->name} ({$user->email})" : null;
                            })
                            ->exists('users', 'id'),
                        Forms\Components\TextInput::make('amount')
                            ->label('Сума (€)')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            // Fat-finger guard, not business policy.
                            ->maxValue(10000)
                            ->rules(['decimal:0,2']),
                        Forms\Components\Textarea::make('reason')
                            ->label('Основание')
                            ->placeholder('напр. Бонус за препоръчан клиент')
                            ->required()
                            // 255-char description column minus the «Бонус: » prefix.
                            ->maxLength(248),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data) {
                        $user = User::find($data['user_id']);

                        if ($user === null || ! $user->isInvestor()) {
                            Notification::make()->title('Само инвеститори могат да получават бонус')
                                ->danger()->send();

                            return;
                        }

                        UserResource::grantBonus($user, $data['amount'], $data['reason']);
                    }),
            ])
            ->actions([
                Action::make('approve')->label('Одобри')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (DepositRequest $r) => $r->status === 'pending' && $r->amount !== null)
                    ->requiresConfirmation()
                    ->action(function (DepositRequest $r) {
                        try {
                            app(DepositService::class)->approve($r->id, auth()->id());
                            Notification::make()->title('Депозит одобрен')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title('Грешка')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')->label('Отхвърли')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (DepositRequest $r) => $r->status === 'pending')
                    ->form([Forms\Components\Textarea::make('admin_note')->label('Причина')->required()])
                    ->action(function (DepositRequest $r, array $data) {
                        app(DepositService::class)->reject($r->id, auth()->id(), $data['admin_note']);
                        Notification::make()->title('Депозит отхвърлен')->danger()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepositRequests::route('/'),
        ];
    }
}
