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
        return self::sanitizeSaveData($this->record, $data);
    }

    /**
     * Sanitize the edit payload so a status change on a LIVE (non-draft) loan
     * always saves cleanly.
     *
     * The blocker: Filament's form dehydrates EVERY field (disabled inputs and a
     * computed placeholder included). On a non-draft loan the model's
     * immutability guard throws the moment ANY field in IMMUTABLE_AFTER_DRAFT
     * looks changed — and the dehydrated payload routinely coerces values
     * (e.g. a nullable FK like co_borrower_id comes back as '' instead of null,
     * or investable_amount as '' instead of its stored number), so the guard
     * fires even though the admin only touched the status. That surfaces as
     * Filament's generic "error-notifications" toast and "Запази" appears broken.
     *
     * Fix: for a non-draft loan, strip the immutable fields from the payload
     * entirely — the status (and the few mutable fields) are all that may change,
     * so coercion can no longer trip the guard. Also keep published_at consistent
     * with the new status. Draft loans are left fully editable.
     *
     * Static + pure so it can be unit-tested without Filament's form harness.
     */
    public static function sanitizeSaveData(Loan $record, array $data): array
    {
        $newStatus = $data['status'] ?? $record->status;

        if ($record->status !== Loan::STATUS_DRAFT) {
            foreach (Loan::IMMUTABLE_AFTER_DRAFT as $field) {
                unset($data[$field]);
            }
        }

        // published_at follows the status: cleared on a revert to draft, stamped
        // when publishing (mirrors the "Публикувай"/"Върни в чернова" actions).
        if ($newStatus === Loan::STATUS_DRAFT) {
            $data['published_at'] = null;
        } elseif ($newStatus === Loan::STATUS_PUBLISHED && empty($record->published_at)) {
            $data['published_at'] = now();
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
