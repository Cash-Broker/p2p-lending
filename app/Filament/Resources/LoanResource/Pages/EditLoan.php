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

    /**
     * Let the admin change a non-draft loan's status (e.g. revert a published
     * loan to draft to hide it from investors) without the model's immutability
     * guard rejecting the save. On a non-draft loan only the status — never the
     * immutable financial/identity fields — may change, so we strip those from
     * the payload regardless of how Filament dehydrates the disabled inputs. We
     * also clear published_at when returning to draft, mirroring the table action.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status !== Loan::STATUS_DRAFT) {
            foreach (Loan::IMMUTABLE_AFTER_DRAFT as $field) {
                unset($data[$field]);
            }

            if (($data['status'] ?? null) === Loan::STATUS_DRAFT) {
                $data['published_at'] = null;
            }
        }

        return $data;
    }

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
