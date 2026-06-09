<?php

namespace App\Filament\Resources\LoanResource\RelationManagers;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\AmortizationService;
use App\Support\Loans\ScheduleBalanceValidator;
use Carbon\Carbon;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AmortizationSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'amortizationSchedules';
    protected static ?string $title = 'Погасителен план';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\DatePicker::make('due_date')->label('Дата')->required(),
            // Guard: scheduled principal across the loan must not exceed the
            // amortization base — over-scheduling would over-pay investors on
            // buyback / early-repayment (both sum the schedule directly).
            Forms\Components\TextInput::make('principal')->label('Главница (€)')->numeric()->required()
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
                ]),
            Forms\Components\TextInput::make('interest')->label('Лихва (€)')->numeric()->required(),
            // Guard: total must equal principal + interest (the payout services
            // trust this identity per row).
            Forms\Components\TextInput::make('total')->label('Общо (€)')->numeric()->required()
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
                ]),
            // Платформена/оригинаторска такса — извън „Общо“ и извън
            // разпределението към инвеститорите.
            Forms\Components\TextInput::make('fees')->label('Такси и комисионни (€)')->numeric()->default(0),
            Forms\Components\Select::make('status')->label('Статус')
                ->options(['pending' => 'Предстои', 'paid' => 'Платено', 'late' => 'Закъснение', 'default' => 'Просрочено'])
                ->default('pending'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
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
                Tables\Columns\TextColumn::make('paid_at')->label('Платено на')->date('d.m.Y'),
            ])
            ->defaultSort('due_date')
            ->headerActions([
                // Calculator: generate the annuity schedule over the investable
                // amount for the chosen first-due date. Replaces any existing rows.
                \Filament\Actions\Action::make('generate_schedule')
                    ->label('Изчисли погасителен план')
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->visible(fn () => in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED]))
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
                        DB::transaction(function () use ($loan, $data) {
                            $loan->amortizationSchedules()->delete();
                            app(AmortizationService::class)->generateSchedule($loan, Carbon::parse($data['first_due_date']));
                        });
                        Notification::make()->title('Погасителният план е генериран')->success()->send();
                    }),
                \Filament\Actions\CreateAction::make()->label('Добави вноска')
                    ->visible(fn () => in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()->label('Редактирай')
                    ->visible(fn ($record) => $record->status !== 'paid'
                        && in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
                \Filament\Actions\DeleteAction::make()->label('Изтрий')
                    ->visible(fn ($record) => $record->status === 'pending'
                        && in_array($this->getOwnerRecord()->status, [Loan::STATUS_DRAFT, Loan::STATUS_PUBLISHED])),
            ]);
    }
}
