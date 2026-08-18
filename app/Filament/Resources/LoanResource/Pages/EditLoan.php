<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Models\Investment;
use App\Models\Loan;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    /**
     * Page layout (boss 2026-08-10): the action buttons — Запази / Линк за
     * инвеститор / Отказ — must sit BELOW «Оферти към инвеститорите», not
     * between the form and the tabs. Order: form → relation managers
     * (offers first) → buttons.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler($this->getSubmitFormLivewireMethodName()),
            $this->getRelationManagersContentComponent(),
            $this->getFormActionsContentComponent(),
        ]);
    }

    /**
     * The buttons render OUTSIDE the <form> element now, so the submit
     * button needs the explicit HTML form-id association to keep working.
     */
    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()->formId('form');
    }

    /**
     * «Линк за инвеститор» joins the bottom button cluster (it used to be
     * a header action) — visible only for private loans, as before.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            LoanResource::shareLinkAction(),
            // Приключването на кредита стои до останалите бутони на самия
            // кредит (Йордан 2026-08-18) — там го търси човек, който вече е
            // отворил кредита, не в списъка.
            LoanResource::earlyClosureAction(),
            LoanResource::partialClosureAction(),
            $this->getCancelFormAction(),
        ];
    }

    /** @see share_linkAction() — same name-based resolver requirement. */
    public function early_closureAction(): Actions\Action
    {
        return LoanResource::earlyClosureAction();
    }

    public function partial_closureAction(): Actions\Action
    {
        return LoanResource::partialClosureAction();
    }

    /**
     * Name-based action resolver for «share_link». Form actions are not
     * auto-cached as page actions in this Filament version (they resolve
     * through the content schema only), so mountAction('share_link') and
     * the test helpers need this {name}Action() hook. The snake_case name
     * is dictated by Filament's lookup: "{$action['name']}Action".
     */
    public function share_linkAction(): Actions\Action
    {
        return LoanResource::shareLinkAction();
    }

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

        // 2026-08-10 (client, explicit): NOTHING is stripped anymore — every
        // field saves in every status, investments or not. Committed
        // investors are protected by their Investment snapshots + frozen
        // contracts, not by locking the loan row.

        // Filament dehydrates empty optional inputs as '' — normalize the two
        // nullable columns so an unlocked-loan save can't write '' into a
        // decimal (would coerce to 0.00 → funding cap 0) or an FK.
        foreach (['investable_amount', 'co_borrower_id'] as $nullable) {
            if (array_key_exists($nullable, $data) && $data[$nullable] === '') {
                $data[$nullable] = null;
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
            // «Линк за инвеститор» moved to the bottom button cluster
            // (getFormActions) per boss request 2026-08-10.
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
