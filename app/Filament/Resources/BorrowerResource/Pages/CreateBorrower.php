<?php

namespace App\Filament\Resources\BorrowerResource\Pages;

use App\Filament\Resources\BorrowerResource;
use App\Models\BorrowerAnonymizedProfile;
use Filament\Resources\Pages\CreateRecord;

class CreateBorrower extends CreateRecord
{
    protected static string $resource = BorrowerResource::class;

    protected function afterCreate(): void
    {
        // Auto-generate anonymized profile for new borrowers
        if (! $this->record->anonymizedProfile) {
            $this->record->anonymizedProfile()->create([
                'risk_class' => 'C',
                'region' => 'Неопределен',
                'loan_purpose' => 'Неопределена',
            ]);
        }
    }
}
