<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Models\Investment;
use App\Models\Loan;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (Loan $record) => $record->status === Loan::STATUS_DRAFT
                    && bccomp($record->funded_amount, '0', 2) <= 0)
                ->before(function (Loan $record) {
                    if (Investment::where('loan_id', $record->id)->exists()) {
                        throw new \Exception('Cannot delete a loan that has investments.');
                    }
                }),
        ];
    }
}
