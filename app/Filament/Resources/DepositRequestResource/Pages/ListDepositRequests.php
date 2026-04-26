<?php

namespace App\Filament\Resources\DepositRequestResource\Pages;

use App\Filament\Resources\DepositRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDepositRequests extends ListRecords
{
    protected static string $resource = DepositRequestResource::class;

    // No CreateAction — DepositRequestResource::canCreate() returns false.
    // The "Захрани сметка" header action on the table itself is the only
    // admin entry point for crediting deposits.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
