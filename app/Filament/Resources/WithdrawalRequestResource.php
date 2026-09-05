<?php

namespace App\Filament\Resources;

use App\Exceptions\AdminReauthenticationException;
use App\Filament\Resources\WithdrawalRequestResource\Pages\ListWithdrawalRequests;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Services\AdminReauthenticationService;
use App\Services\WithdrawalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Тегления';

    protected static string|UnitEnum|null $navigationGroup = 'Финанси';

    protected static ?string $pluralModelLabel = 'Тегления';

    protected static ?string $modelLabel = 'Теглене';

    protected static ?int $navigationSort = 3;

    /**
     * Wall-clock timezone for the dates in this list — prod's app tz is UTC,
     * the admin thinks in Sofia time. Same idiom as DepositRequestResource.
     */
    private const DISPLAY_TIMEZONE = 'Europe/Sofia';

    /**
     * SEC-11 (audit 2026-09-01, owner 2026-09-03): the full IBAN is revealed
     * only where a wire is made or reconciled. `pending` is deliberately out —
     * the approver decides on the masked value (SEC-01 scope, group B).
     */
    public const IBAN_REVEALABLE_STATUSES = ['approved', 'processed'];

    /**
     * «Покажи IBAN» — password re-entry, audit row, then the IBAN in a modal.
     *
     * Filament mounts hidden actions by name (visibility is UI sugar, not a
     * boundary) but re-checks visibility at call time, so the status gate is
     * ALSO re-read from the DB inside the closure. The audit row is written
     * BEFORE the reveal: a failing insert means no reveal. The revealed value
     * never enters this action's own form state — the modal is replaced by
     * `show_iban`, whose content renders straight from the row behind a
     * page-scoped `#[Locked]` grant on ListWithdrawalRequests.
     */
    public static function revealIbanAction(): Action
    {
        return Action::make('reveal_iban')
            ->label('Покажи IBAN')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (WithdrawalRequest $r) => in_array($r->status, self::IBAN_REVEALABLE_STATUSES, true))
            ->modalHeading(fn (WithdrawalRequest $r) => "IBAN за превод — теглене #{$r->id}")
            ->modalDescription('Пълният IBAN се показва само след повторно въвеждане на вашата парола. Всяко показване се записва в одитния дневник.')
            ->modalWidth('md')
            ->closeModalByClickingAway(false)
            ->mountUsing(function (Schema $schema, $livewire): void {
                if ($livewire instanceof ListWithdrawalRequests) {
                    $livewire->clearIbanRevealGrant();
                }
                $schema->fill();
            })
            ->form([
                Forms\Components\TextInput::make('password')
                    ->label('Вашата парола')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('current-password'),
            ])
            ->modalSubmitActionLabel('Покажи')
            ->action(function (WithdrawalRequest $r, array $data, Action $action, $livewire): void {
                $fresh = WithdrawalRequest::whereKey($r->getKey())
                    ->whereIn('status', self::IBAN_REVEALABLE_STATUSES)
                    ->first();
                if ($fresh === null) {
                    Notification::make()->title('IBAN не може да бъде показан')
                        ->body('Показва се само за одобрени и обработени заявки.')
                        ->warning()->send();

                    return;
                }

                try {
                    app(AdminReauthenticationService::class)->verify(auth()->user(), (string) ($data['password'] ?? ''), 'withdrawal_iban_reveal');
                } catch (AdminReauthenticationException $e) {
                    Notification::make()
                        ->title($e->retryAfterSeconds > 0 ? 'Достъпът е временно заключен' : 'Грешна парола')
                        ->body($e->getMessage())
                        ->danger()->send();
                    $action->halt();
                }

                AuditLog::recordAccess(WithdrawalRequest::class, $fresh->id, [
                    'field' => 'iban',
                    'purpose' => 'bank_transfer',
                    'status' => $fresh->status,
                    'iban_suffix' => '****'.substr((string) $fresh->iban, -4),
                    'amount' => (string) $fresh->amount,
                ]);

                if ($livewire instanceof ListWithdrawalRequests) {
                    $livewire->grantIbanReveal($fresh->id);
                }

                $livewire->replaceMountedAction('show_iban', [], ['table' => true, 'recordKey' => $fresh->getKey()]);
            });
    }

    /**
     * The second half of «Покажи IBAN»: opened by replaceMountedAction above and
     * offered as «IBAN за превод» while the page-scoped grant is alive; renders
     * the IBAN only while that grant covers THIS row.
     */
    public static function showIbanAction(): Action
    {
        return Action::make('show_iban')
            ->label('IBAN за превод')
            ->icon('heroicon-o-banknotes')
            ->color('gray')
            ->visible(fn (WithdrawalRequest $r, $livewire): bool => $livewire instanceof ListWithdrawalRequests && $livewire->hasIbanRevealGrant($r->id))
            ->modalHeading(fn (WithdrawalRequest $r) => "IBAN за превод — теглене #{$r->id}")
            ->modalWidth('md')
            ->modalContent(function (WithdrawalRequest $r, $livewire) {
                $granted = $livewire instanceof ListWithdrawalRequests && $livewire->hasIbanRevealGrant($r->id);

                return view('filament.modals.iban-reveal', [
                    'request' => $r,
                    'iban' => $granted ? (string) $r->iban : null,
                    'wireAmount' => self::wireAmountFor($r),
                    'beneficiary' => self::beneficiaryNameFor($r),
                    'lastReveal' => $granted ? self::previousRevealFor($r) : null,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Затвори')
            ->action(fn () => null);
    }

    /** The NET amount that leaves the bank: the ledger row, or amount − fee_quoted before approval. */
    private static function wireAmountFor(WithdrawalRequest $r): string
    {
        $ledger = Transaction::where('reference', "withdrawal_request:{$r->id}")
            ->where('type', Transaction::TYPE_WITHDRAWAL)
            ->value('amount');

        return $ledger !== null
            ? bcadd((string) $ledger, '0', 2)
            : bcsub((string) $r->amount, (string) ($r->fee_quoted ?? '0.00'), 2);
    }

    private static function beneficiaryNameFor(WithdrawalRequest $r): string
    {
        $user = $r->user;
        if ($user === null) {
            return "потребител #{$r->user_id}";
        }
        $legalName = $user->isLegalEntity() ? $user->legalEntityProfile?->legal_name : null;

        return $legalName ?: $user->name;
    }

    /** The reveal BEFORE the one just written — two admins must not both wire. */
    private static function previousRevealFor(WithdrawalRequest $r): ?AuditLog
    {
        return AuditLog::where('model_type', WithdrawalRequest::class)
            ->where('model_id', $r->id)
            ->where('action', 'viewed')
            ->orderByDesc('id')
            ->skip(1)
            ->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Инвеститор')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('EUR'),
                Tables\Columns\TextColumn::make('iban')->label('IBAN')->formatStateUsing(fn (WithdrawalRequest $r) => $r->maskedIban()),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Чакащо', 'approved' => 'Одобрено', 'rejected' => 'Отхвърлено', 'processed' => 'Обработено', default => $state
                    })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected', 'info' => 'processed']),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->sortable(),
                // Кога парите реално напускат сметката. Стъпва при «Одобри»
                // (там е debitReserved) и се пре-стъпва при «Обработено», щом
                // преводът е пуснат — чакащите заявки нямат такъв момент и
                // показват «—». Подредбата умишлено остава по датата на
                // ЗАЯВКАТА (Рени 2026-08-18): списъкът е работна опашка.
                Tables\Columns\TextColumn::make('processed_at')->label('Изплатен на')
                    ->dateTime('d.m.Y H:i', self::DISPLAY_TIMEZONE)
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Чакащо', 'approved' => 'Одобрено', 'rejected' => 'Отхвърлено', 'processed' => 'Обработено'])])
            ->actions([
                self::revealIbanAction(),
                self::showIbanAction(),
                // Every outcome gets a BG toast (CLAUDE.md idiom 9): a second
                // admin winning the race (ModelNotFound), a KYC/fee refusal
                // (ValidationException) or anything unexpected must never
                // surface as a raw Livewire error on a money screen.
                Action::make('approve')->label('Одобри')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'pending')->requiresConfirmation()
                    ->action(function (WithdrawalRequest $r) {
                        try {
                            app(WithdrawalService::class)->approve($r->id, auth()->id());
                            Notification::make()->title('Теглене одобрено')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Тегленето не може да бъде одобрено')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->danger()->send();
                        } catch (ModelNotFoundException) {
                            Notification::make()->title('Заявката вече е обработена')
                                ->body('Друг администратор я обработи междувременно. Провери списъка.')
                                ->warning()->send();
                        } catch (\Throwable $e) {
                            Log::error('Withdrawal approve action failed', ['withdrawal_id' => $r->id, 'error' => $e->getMessage()]);
                            Notification::make()->title('Грешка при одобрение')
                                ->body('Парите НЕ са дебитирани — провери лога.')
                                ->danger()->send();
                        }
                    }),
                Action::make('reject')->label('Отхвърли')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'pending')
                    ->form([Forms\Components\Textarea::make('admin_note')->label('Причина')->required()->maxLength(255)])
                    ->action(function (WithdrawalRequest $r, array $data) {
                        try {
                            app(WithdrawalService::class)->reject($r->id, auth()->id(), $data['admin_note']);
                            Notification::make()->title('Теглене отхвърлено')->danger()->send();
                        } catch (ModelNotFoundException) {
                            Notification::make()->title('Заявката вече е обработена')
                                ->body('Друг администратор я обработи междувременно. Провери списъка.')
                                ->warning()->send();
                        } catch (\Throwable $e) {
                            Log::error('Withdrawal reject action failed', ['withdrawal_id' => $r->id, 'error' => $e->getMessage()]);
                            Notification::make()->title('Грешка при отхвърляне')->body('Провери лога.')->danger()->send();
                        }
                    }),
                Action::make('mark_processed')->label('Обработено')->icon('heroicon-o-check')
                    ->visible(fn (WithdrawalRequest $r) => $r->status === 'approved')->requiresConfirmation()
                    ->action(function (WithdrawalRequest $r) {
                        try {
                            \DB::transaction(function () use ($r) {
                                $r = WithdrawalRequest::where('id', $r->id)->where('status', 'approved')->lockForUpdate()->firstOrFail();
                                $r->update(['status' => 'processed', 'processed_at' => now(), 'processed_by' => auth()->id()]);
                            });
                            Notification::make()->title('Обработено')->success()->send();
                        } catch (ModelNotFoundException) {
                            Notification::make()->title('Заявката вече е обработена')->warning()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWithdrawalRequests::route('/')];
    }
}
