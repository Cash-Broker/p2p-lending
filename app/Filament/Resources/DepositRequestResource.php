<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepositRequestResource\Pages;
use App\Models\DepositRequest;
use App\Services\DepositService;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class DepositRequestResource extends Resource
{
    protected static ?string $model = DepositRequest::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationLabel = 'Депозити';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
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
                    ->formatStateUsing(fn (string $state) => match ($state) { 'pending' => 'Чакащ', 'approved' => 'Одобрен', 'rejected' => 'Отхвърлен', default => $state })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('admin_note')->label('Бележка')->limit(30)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')->date('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('expires_at')->label('Изтича')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
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
                \Filament\Actions\Action::make('credit_account')
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
                                if ($deposit->isExpired()) {
                                    return "⚠️ Кодът на {$deposit->user->name} е изтекъл на " . $deposit->expires_at->format('d.m.Y') . '.';
                                }
                                return "✓ {$deposit->user->name} ({$deposit->user->email})";
                            }),
                        Forms\Components\TextInput::make('amount')
                            ->label('Сума по bank statement (€)')
                            ->numeric()->required()->minValue(1)->step(0.01),
                        Forms\Components\TextInput::make('bank_reference')
                            ->label('Bank reference (от statement-а)')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Уникален идентификатор на превода в банковото извлечение. Същият превод не може да се credit-не два пъти.'),
                    ])
                    ->action(function (array $data) {
                        $code = strtoupper(trim($data['reference_code']));

                        // Lock the row so two admins racing on the same code
                        // can't both credit (one will fail the firstOrFail
                        // inside DepositService::approve once the other
                        // commits status=approved).
                        $deposit = DepositRequest::where('reference_code', $code)
                            ->where('status', 'pending')
                            ->lockForUpdate()
                            ->first();

                        if (! $deposit) {
                            Notification::make()->title('Невалиден код')
                                ->body("Код {$code} не съществува или вече е обработен.")
                                ->danger()->send();
                            return;
                        }

                        if ($deposit->isExpired()) {
                            Notification::make()->title('Кодът е изтекъл')
                                ->body("Кодът {$code} на {$deposit->user->name} е изтекъл. Помоли потребителя да генерира нов от страницата за депозит.")
                                ->danger()->send();
                            return;
                        }

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

                        $deposit->update([
                            'amount' => number_format((float) $data['amount'], 2, '.', ''),
                            'bank_reference' => $data['bank_reference'],
                        ]);

                        try {
                            app(DepositService::class)->approve($deposit->id, auth()->id());
                            Notification::make()
                                ->title("Сметката на {$deposit->user->name} е захранена с {$data['amount']} €")
                                ->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title('Грешка при credit')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('approve')->label('Одобри')->icon('heroicon-o-check-circle')->color('success')
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
                \Filament\Actions\Action::make('reject')->label('Отхвърли')->icon('heroicon-o-x-circle')->color('danger')
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
