<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepositRequestResource\Pages;
use App\Models\DepositRequest;
use App\Services\DepositService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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
                Tables\Columns\TextColumn::make('created_at')->label('Дата')->date('d.m.Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
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
                // «Начисли бонус» — same working rhythm as «Захрани сметка»
                // (boss 2026-08-09: «както е при баш депозита, но с бонус»):
                // the admin pastes the user's DEP code, the system resolves
                // the person, then amount + reason. The code is used ONLY to
                // identify the user — it is NOT consumed (deposit codes are
                // retired exclusively by approve/reject, client decision
                // 2026-07-17), and the money lands as a TYPE_BONUS ledger
                // row, never as a deposit (no wire behind it). Any code the
                // user ever had works — historical codes still identify
                // their owner unambiguously (unique per request).
                Action::make('grant_bonus')
                    ->label('Начисли бонус')
                    ->icon('heroicon-o-gift')
                    ->color('warning')
                    ->modalHeading('Начисли бонус')
                    ->modalDescription('Въведи кода на потребителя (от неговата страница „Депозиране“). Кодът служи само за намиране на човека — остава си активен за депозити. Сумата се записва като „Бонус“, не като депозит.')
                    ->form([
                        Forms\Components\TextInput::make('reference_code')
                            ->label('Код на потребителя')
                            ->placeholder('DEP-AB12CD34')
                            ->required()
                            ->live(debounce: 400)
                            ->rules(['regex:/^DEP-[A-Z0-9]{8}$/i'])
                            ->validationMessages(['regex' => 'Кодът трябва да е във формат DEP-XXXXXXXX (8 знака букви/цифри).'])
                            ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state ?? ''))),
                        Forms\Components\Placeholder::make('resolved_user')
                            ->label('Засечен потребител')
                            ->content(function (callable $get) {
                                $code = strtoupper(trim((string) $get('reference_code')));
                                if ($code === '' || ! preg_match('/^DEP-[A-Z0-9]{8}$/', $code)) {
                                    return '—';
                                }
                                $user = DepositRequest::where('reference_code', $code)
                                    ->with('user')
                                    ->first()?->user;
                                if (! $user) {
                                    return '⚠️ Няма потребител с този код.';
                                }
                                if (! $user->wallet) {
                                    return "⚠️ Акаунтът на {$user->name} е закрит — не може да получи бонус.";
                                }

                                return "✓ {$user->name} ({$user->email})";
                            }),
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
                        $code = strtoupper(trim($data['reference_code']));

                        $user = DepositRequest::where('reference_code', $code)
                            ->with('user')
                            ->first()?->user;

                        if ($user === null) {
                            Notification::make()->title('Невалиден код')
                                ->body("Няма потребител с код {$code}.")
                                ->danger()->send();

                            return;
                        }

                        if (! $user->isInvestor()) {
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
