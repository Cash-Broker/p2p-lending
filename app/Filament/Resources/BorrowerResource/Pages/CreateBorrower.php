<?php

namespace App\Filament\Resources\BorrowerResource\Pages;

use App\Filament\Resources\BorrowerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBorrower extends CreateRecord
{
    protected static string $resource = BorrowerResource::class;

    protected function afterCreate(): void
    {
        // Auto-generate the investor-facing anonymized profile. Shared with the
        // inline borrower-creation path on the loan form (LoanResource).
        $this->record->ensureAnonymizedProfile();
    }
}
