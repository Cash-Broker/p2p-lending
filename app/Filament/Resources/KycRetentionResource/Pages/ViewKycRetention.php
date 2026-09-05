<?php

namespace App\Filament\Resources\KycRetentionResource\Pages;

use App\Filament\Resources\KycRetentionResource;
use App\Models\AuditLog;
use App\Models\KycRetention;
use Filament\Resources\Pages\ViewRecord;

class ViewKycRetention extends ViewRecord
{
    protected static string $resource = KycRetentionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // SEC-16: opening the archive record is itself an access event; the three
        // images add one `viewed` row each through the id-addressed route.
        AuditLog::recordAccess(KycRetention::class, (int) $this->record->getKey(), [
            'document' => 'kyc_retention_record',
            'subject_user_id' => $this->record->user_id,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
