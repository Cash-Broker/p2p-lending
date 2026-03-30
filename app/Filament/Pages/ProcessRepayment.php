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
                            ->mapWithKeys(fn (Loan $loan) => [$loan->id => "#{$loan->id} — {$loan->originator->name} — {$loan->amount} € ({$loan->type})"]))
                        ->searchable()->required(),
                    Forms\Components\TextInput::make('principal_amount')->label('Главница (€)')->numeric()->required()->minValue(0)->step(0.01),
                    Forms\Components\TextInput::make('interest_amount')->label('Лихва (€)')->numeric()->required()->minValue(0)->step(0.01),
                    Forms\Components\Select::make('amortization_schedule_id')->label('Ред от погасителен план')
                        ->options(function (callable $get) {
                            $loanId = $get('loan_id');
                            if (! $loanId) return [];
                            return \App\Models\AmortizationSchedule::where('loan_id', $loanId)->where('status', 'pending')->get()
                                ->mapWithKeys(fn ($s) => [$s->id => $s->due_date->format('d.m.Y') . " — {$s->total} €"]);
                        })->nullable()->reactive(),
                ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        try {
            app(RepaymentService::class)->processRepayment(
                (int) $data['loan_id'],
                number_format((float) $data['principal_amount'], 2, '.', ''),
                number_format((float) $data['interest_amount'], 2, '.', ''),
                $data['amortization_schedule_id'] ? (int) $data['amortization_schedule_id'] : null,
            );

            $loan = Loan::find($data['loan_id']);
            $total = bcadd($data['principal_amount'], $data['interest_amount'], 2);

            Notification::make()->title('Погашение обработено')
                ->body("Кредит #{$loan->id}: {$total} € разпределени.")
                ->success()->send();

            $this->form->fill();
        } catch (\Exception $e) {
            Notification::make()->title('Грешка')->body($e->getMessage())->danger()->send();
        }
    }
}
