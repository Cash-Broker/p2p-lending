<?php

namespace App\Filament\Resources\KycRetentionResource\Pages;

use App\Filament\Resources\KycRetentionResource;
use Filament\Resources\Pages\ListRecords;

class ListKycRetentions extends ListRecords
{
    protected static string $resource = KycRetentionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
