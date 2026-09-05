<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\AmortizationService;
use App\Services\Loans\BorrowerPlanService;
use App\Support\Loans\ScheduleBalanceValidator;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Two faces (PAY-13, owner 2026-09-03):
 *   • legacy loans — the per-loan amortization schedule the admin settles through
 *     «Погашения» (byte-identical to before);
 *   • offer loans — the BORROWER TRACKING PLAN: dates are what matter, amounts are
 *     informational and never distributed (investors are paid by their own plans).
 *     The admin attests each borrower installment here; an unrecorded one makes
 *     the loan `late` after the grace period (investor e-mail, Buyback Queue and —
 *     only with payout_pause_enabled — the payout pause).
 */
class AmortizationSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'amortizationSchedules';

    private ?bool $usesOffers = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Loan && $ownerRecord->usesOffers()
            ? 'План на кредитополучателя (проследяване)'
            : 'Погасителен план';
    }

    private function usesOffers(): bool
    {
        return $this->usesOffers ??= (bool) $this->getOwnerRecord()->usesOffers();
    }

    private function ownerStatus(): string
    {
        return (string) $this->getOwnerRecord()->status;
    }

    /** Legacy rows are edited only before money is in (draft/published). */
    private function legacyEditable(): bool
    {
        return in_array($this->ownerStatus(), [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED], true);
    }

    /** The borrower tracker lives on active/late offer loans only. */
    private function trackerLoanLive(): bool
    {
        return $this->usesOffers() && in_array($this->ownerStatus(), [Loan::STATUS_ACTIVE, Loan::STATUS_LATE], true);
    }

    private function hasTracker(): bool
    {
        return $this->getOwnerRecord()->amortizationSchedules()->borrowerTracker()->exists();
    }

    public function form(Schema $form): Schema
    {
        // Tracker rows: only the date is editable — amounts are informational and
        // status changes go through the attestation actions. Filament v5 still
        // validates + dehydrates disabled fields, hence the explicit pair.
        $frozenForTracker = fn (Field $field): Field => $field
            ->disabled(fn () => $this->usesOffers())
            ->dehydrated(fn () => ! $this->usesOffers())
            ->validatedWhenNotDehydrated(false);

        return $form->schema([
            Forms\Components\DatePicker::make('due_date')->label('Дата')->required(),
            // Guard: scheduled principal across the loan must not exceed the
            // amortization base — over-scheduling would over-pay investors on
            // buyback / early-repayment (both sum the schedule directly).
            $frozenForTracker(Forms\Components\TextInput::make('principal')->label('Главница (€)')->numeric()->required()
                ->rules([
                    fn ($livewire, ?AmortizationSchedule $record): Closure => function (string $attribute, $value, Closure $fail) use ($livewire, $record) {
                        $error = ScheduleBalanceValidator::principalOvershootError(
                            $livewire->getOwnerRecord(),
                            $record?->getKey(),
                            (string) $value,
                        );
                        if ($error !== null) {
                            $fail($error);
                        }
                    },
                ])),
            $frozenForTracker(Forms\Components\TextInput::make('interest')->label('Лихва (€)')->numeric()->required()),
            // Guard: total must equal principal + interest (the payout services
            // trust this identity per row).
            $frozenForTracker(Forms\Components\TextInput::make('total')->label('Общо (€)')->numeric()->required()
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                        $error = ScheduleBalanceValidator::rowTotalError(
                            (string) ($get('principal') ?? ''),
                            (string) ($get('interest') ?? ''),
                            (string) $value,
                        );
                        if ($error !== null) {
                            $fail($error);
                        }
                    },
                ])),
            // Платформена/оригинаторска такса — извън „Общо“ и извън
            // разпределението към инвеститорите.
            $frozenForTracker(Forms\Components\TextInput::make('fees')->label('Такси и комисионни (€)')->numeric()->default(0)),
            $frozenForTracker(Forms\Components\Select::make('status')->label('Статус')
                ->options(['pending' => 'Предстои', 'paid' => 'Платено', 'late' => 'Закъснение', 'default' => 'Просрочено'])
                ->default('pending')),
        ]);
    }

    public function table(Table $table): Table
    {
        $usesOffers = $this->usesOffers();

        return $table
            ->description($usesOffers
                ? 'Датите са водещи. Сумите са ориентировъчни и НЕ се разпределят към инвеститорите (те се плащат по собствените си планове). Отбелязвайте всяка вноска, която кредитополучателят плати — иначе кредитът става „Закъснял“ след гратисния период и инвеститорите получават имейл.'
                : null)
            ->columns([
                Tables\Columns\TextColumn::make('due_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('principal')->label('Главница')->money('EUR'),
                Tables\Columns\TextColumn::make('interest')->label('Лихва')->money('EUR'),
                Tables\Columns\TextColumn::make('total')->label('Общо')->money('EUR'),
                Tables\Columns\TextColumn::make('fees')->label('Такси')->money('EUR')->toggleable(),
                Tables\Columns\BadgeColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Предстои', 'paid' => 'Платено', 'late' => 'Закъснение', 'default' => 'Просрочено', default => $state,
                    })
                    ->colors(['warning' => 'pending', 'success' => 'paid', 'danger' => fn ($state) => in_array($state, ['late', 'default'])]),
                Tables\Columns\TextColumn::make('days_late')
                    ->label('Дни закъснение')
                    ->formatStateUsing(fn ($state, $record) => $record->status === 'late' ? $state : '—')
                    ->color(fn ($state, $record) => $record->status === 'late' && $state >= 30 ? 'danger' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('became_late_at')
                    ->label('От кога е late')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('borrower_paid_on')
                    ->label('Платена от кредитополучателя на')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->visible($usesOffers),
                Tables\Columns\TextColumn::make('paid_at')->label($usesOffers ? 'Отбелязано на' : 'Платено на')->date('d.m.Y'),
            ])
            ->defaultSort('due_date')
            ->headerActions([
                // Calculator: generate the annuity schedule over the investable
                // amount for the chosen first-due date. Replaces any existing rows.
                Action::make('generate_schedule')
                    ->label('Изчисли погасителен план')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->visible(fn () => $this->legacyEditable())
                    ->modalHeading('Изчисли погасителен план')
                    ->modalDescription('Генерира анюитетен план върху „Свободни за инвестиция“ за срока и доходността на кредита. Съществуващите вноски ще бъдат заменени.')
                    ->form([
                        Forms\Components\DatePicker::make('first_due_date')
                            ->label('Първа дата на погасяване')
                            ->default(now()->addDays(30))
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $loan = $this->getOwnerRecord();

                        // The legacy annuity calculator runs off the LOAN-level
                        // rate; since 2026-08-10 the form no longer collects it
                        // (offer loans price via the three offers instead).
                        if ($loan->interest_rate === null) {
                            Notification::make()
                                ->title('Кредитът няма зададена доходност')
                                ->body('Легаси планът се смята от лихвата на кредита, а тя не е попълнена. Офертните кредити получават графиците си автоматично при активиране.')
                                ->danger()->send();

                            return;
                        }

                        DB::transaction(function () use ($loan, $data) {
                            $loan->amortizationSchedules()->delete();
                            app(AmortizationService::class)->generateSchedule($loan, Carbon::parse($data['first_due_date']));
                        });
                        Notification::make()->title('Погасителният план е генериран')->success()->send();
                    }),
                $this->createTrackerAction(),
                $this->markPaidThroughAction(),
                CreateAction::make()->label('Добави вноска')
                    ->visible(fn () => $this->legacyEditable()),
            ])
            ->actions([
                $this->borrowerPaidAction(),
                EditAction::make()->label('Редактирай')
                    ->visible(fn (AmortizationSchedule $record) => ($record->status !== 'paid' && $this->legacyEditable())
                        // Tracker rows: due-date corrections on unpaid rows of a live loan.
                        || ($this->trackerLoanLive() && $record->isBorrowerTracker() && $record->status === 'pending')),
                DeleteAction::make()->label('Изтрий')
                    ->visible(fn ($record) => $record->status === 'pending' && $this->legacyEditable()),
            ]);
    }

    /**
     * PAY-13: create the borrower tracker for a loan that was live before the
     * feature (new loans get theirs at activation). Refuses to create already
     * overdue unpaid rows without an explicit acknowledgement — they make the
     * loan `late` the same night and the investors get an e-mail.
     */
    private function createTrackerAction(): Action
    {
        return Action::make('create_tracker')
            ->label('Създай план на кредитополучателя')
            ->icon('heroicon-o-clipboard-document-list')
            ->color('primary')
            ->visible(fn () => $this->trackerLoanLive() && ! $this->hasTracker())
            ->modalHeading('План на кредитополучателя')
            ->modalDescription('Линеен план по датите на кредитополучателя — само за проследяване на закъснения. Сумите са ориентировъчни; нищо не се разпределя към инвеститорите.')
            ->form([
                Forms\Components\DatePicker::make('first_due_date')
                    ->label('Първа вноска на кредитополучателя')
                    ->required()
                    ->live(),
                Forms\Components\DatePicker::make('paid_through')
                    ->label('Платени до (вкл.)')
                    ->maxDate(today(BorrowerPlanService::BUSINESS_TZ))
                    ->live()
                    ->helperText('Вноските с падеж до тази дата се записват като платени от кредитополучателя.'),
                Forms\Components\Placeholder::make('overdue_preview')
                    ->label('Проверка')
                    ->content(fn (Get $get) => ($overdue = $this->overduePreview($get)) > 0
                        ? sprintf('%d вноски ще са с изтекъл падеж и НЕОТБЕЛЯЗАНИ — кредитът ще стане „Закъснял“ при следващата нощна проверка (03:30).', $overdue)
                        : 'Няма вноски с изтекъл падеж без отбелязване.'),
                Forms\Components\Checkbox::make('confirm_overdue')
                    ->label('Разбирам, че кредитът ще стане „Закъснял“ при следващата нощна проверка и инвеститорите ще получат имейл')
                    ->visible(fn (Get $get) => $this->overduePreview($get) > 0)
                    ->accepted(fn (Get $get) => $this->overduePreview($get) > 0),
            ])
            ->action(function (array $data): void {
                try {
                    $count = app(BorrowerPlanService::class)->generate(
                        $this->getOwnerRecord(),
                        Carbon::parse($data['first_due_date']),
                        filled($data['paid_through'] ?? null) ? Carbon::parse($data['paid_through']) : null,
                        auth()->id(),
                    );
                    Notification::make()->title("Планът на кредитополучателя е създаден ({$count} вноски)")->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('Планът не е създаден')->body($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    Log::error('Borrower tracker creation failed', ['loan_id' => $this->getOwnerRecord()->id, 'error' => $e->getMessage()]);
                    Notification::make()->title('Грешка при създаване на плана')->body('Нищо не е записано — провери лога.')->danger()->send();
                }
            });
    }

    private function overduePreview(Get $get): int
    {
        $first = $get('first_due_date');
        if (blank($first)) {
            return 0;
        }

        try {
            return app(BorrowerPlanService::class)->overduePreview(
                $this->getOwnerRecord(),
                Carbon::parse($first),
                filled($get('paid_through')) ? Carbon::parse($get('paid_through')) : null,
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /** PAY-13: bulk attestation — every unpaid tracker row due on/before the date. */
    private function markPaidThroughAction(): Action
    {
        return Action::make('mark_paid_through')
            ->label('Отбележи платени до дата')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn () => $this->trackerLoanLive() && $this->hasTracker())
            ->modalHeading('Отбележи вноските на кредитополучателя като платени')
            ->modalDescription('Записва само факта. Не движи пари — инвеститорите се плащат по собствените си планове.')
            ->form([
                Forms\Components\DatePicker::make('through')
                    ->label('Платени до (вкл.)')
                    ->default(today(BorrowerPlanService::BUSINESS_TZ))
                    ->maxDate(today(BorrowerPlanService::BUSINESS_TZ))
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $count = app(BorrowerPlanService::class)->markPaidThrough(
                        (int) $this->getOwnerRecord()->id,
                        Carbon::parse($data['through']),
                        (int) auth()->id(),
                    );
                    Notification::make()->title("{$count} вноски отбелязани като платени")->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('Нищо не е отбелязано')->body($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    Log::error('Borrower tracker bulk attestation failed', ['loan_id' => $this->getOwnerRecord()->id, 'error' => $e->getMessage()]);
                    Notification::make()->title('Грешка при отбелязване')->body('Нищо не е записано — провери лога.')->danger()->send();
                }
            });
    }

    /** PAY-13: attest ONE borrower installment. Records the fact, moves no money. */
    private function borrowerPaidAction(): Action
    {
        return Action::make('borrower_paid')
            ->label('Платена от кредитополучателя')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (AmortizationSchedule $record) => $this->trackerLoanLive()
                && $record->isBorrowerTracker()
                && in_array($record->status, ['pending', 'late'], true))
            ->modalHeading('Вноска, платена от кредитополучателя')
            ->modalDescription('Записва само факта. Не движи пари — инвеститорите се плащат по собствените си планове.')
            ->form([
                Forms\Components\DatePicker::make('borrower_paid_on')
                    ->label('Платена на')
                    ->default(today(BorrowerPlanService::BUSINESS_TZ))
                    ->maxDate(today(BorrowerPlanService::BUSINESS_TZ))
                    ->required(),
            ])
            ->action(function (AmortizationSchedule $record, array $data): void {
                try {
                    app(BorrowerPlanService::class)->recordBorrowerPayment(
                        (int) $record->loan_id,
                        (int) $record->id,
                        Carbon::parse($data['borrower_paid_on']),
                        (int) auth()->id(),
                    );
                    Notification::make()->title('Вноската е отбелязана като платена от кредитополучателя')->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('Вноската не е отбелязана')->body($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    Log::error('Borrower installment attestation failed', ['row_id' => $record->id, 'error' => $e->getMessage()]);
                    Notification::make()->title('Грешка при отбелязване')->body('Нищо не е записано — провери лога.')->danger()->send();
                }
            });
    }
}
