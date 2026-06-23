<?php

namespace App\Filament\Pages;

use App\Models\Loan;
use App\Services\RepaymentService;
use BackedEnum;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class ProcessRepayment extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Погашения';
    protected static ?string $title = 'Обработка на погашение';
    protected static string | UnitEnum | null $navigationGroup = 'Финанси';
    protected static ?int $navigationSort = 4;
    protected string $view = 'filament.pages.process-repayment';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Section::make('Въведете погашение')->schema([
                    Forms\Components\Select::make('loan_id')->label('Кредит')
                        ->options(Loan::where('status', Loan::STATUS_ACTIVE)->with('originator')->get()
                            ->mapWithKeys(fn(Loan $loan) => [$loan->id => "#{$loan->id} — {$loan->originator->name} — {$loan->amount} € ({$loan->type})"]))
                        ->searchable()->required()->live(),
                    // Amounts are NEVER typed by the admin (audit CRITICAL fix).
                    // The installment IS the amount: RepaymentService derives
                    // principal/interest from this locked row, so a fat-finger
                    // can't over-distribute and mint unbacked balance. The id is
                    // also REQUIRED so the 'paid' duplicate guard always runs —
                    // no double-submit can re-pay the same installment.
                    Forms\Components\Select::make('amortization_schedule_id')->label('Вноска от погасителен план')
                        ->options(function (callable $get) {
                            $loanId = $get('loan_id');
                            if (! $loanId) return [];
                            return \App\Models\AmortizationSchedule::where('loan_id', $loanId)
                                ->whereIn('status', ['pending', 'late'])
                                ->orderBy('due_date')
                                ->get()
                                ->mapWithKeys(fn($s) => [$s->id =>
                                    $s->due_date->format('d.m.Y')
                                    . " — главница {$s->principal} € + лихва {$s->interest} € = {$s->total} €",
                                ]);
                        })
                        ->required()
                        ->validationMessages(['required' => 'Изберете конкретна вноска от плана. Сумите се изчисляват от нея — не се въвеждат ръчно.'])
                        ->live(),
                    Forms\Components\Placeholder::make('installment_breakdown')
                        ->label('Което ще бъде разпределено')
                        ->content(function (callable $get) {
                            $id = $get('amortization_schedule_id');
                            if (! $id) {
                                return '—';
                            }
                            $s = \App\Models\AmortizationSchedule::find($id);
                            if (! $s) {
                                return '—';
                            }

                            return "Главница {$s->principal} € + лихва {$s->interest} € = {$s->total} € (към инвеститорите)";
                        }),
                ])->columns(1),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $schedule = \App\Models\AmortizationSchedule::find($data['amortization_schedule_id'] ?? null);
        if (! $schedule) {
            Notification::make()->title('Грешка')
                ->body('Изберете валидна вноска от погасителния план.')
                ->danger()->send();
            return;
        }
        if ($schedule->status === 'paid') {
            Notification::make()->title('Грешка')
                ->body('Тази вноска вече е платена.')
                ->danger()->send();
            return;
        }

        try {
            // Amounts are derived from the installment inside the service —
            // we pass only the loan + installment ids.
            app(RepaymentService::class)->processRepayment(
                (int) $data['loan_id'],
                (int) $data['amortization_schedule_id'],
            );

            Notification::make()->title('Погашение обработено')
                ->body("Кредит #{$data['loan_id']}: {$schedule->total} € разпределени към инвеститорите.")
                ->success()->send();

            $this->form->fill();
        } catch (\Exception $e) {
            Notification::make()->title('Грешка')->body($e->getMessage())->danger()->send();
        }
    }
}
