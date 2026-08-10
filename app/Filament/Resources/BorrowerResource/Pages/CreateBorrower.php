<?php

namespace App\Filament\Resources\BorrowerResource\Pages;

use App\Filament\Resources\BorrowerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBorrower extends CreateRecord
{
    protected static string $resource = BorrowerResource::class;

    protected function afterCreate(): void
    {
        // Investor-facing anonymized profile, seeded from the create form's
        // «Профил за инвеститора» section (fields are dehydrated(false) so
        // they never hit the borrowers table; the raw form state still
        // carries them). Shared with the inline creation path on the loan
        // form (LoanResource::createBorrowerInline).
        $this->record->ensureAnonymizedProfile([
            'risk_class' => $this->data['profile_risk_class'] ?? null,
            'region' => $this->data['profile_region'] ?? null,
            'loan_purpose' => $this->data['profile_loan_purpose'] ?? null,
            'collateral_type' => $this->data['profile_collateral_type'] ?? null,
            'age_group' => $this->data['profile_age_group'] ?? null,
        ]);
    }
}
