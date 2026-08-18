<?php

namespace App\Filament\Resources\BonusGrantResource\Pages;

use App\Filament\Resources\BonusGrantResource;
use Filament\Resources\Pages\ListRecords;

class ListBonusGrants extends ListRecords
{
    protected static string $resource = BonusGrantResource::class;

    // Read-only register: bonuses are granted from «Потребители» / «Депозити»
    // and by the promo engine, never created here.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
