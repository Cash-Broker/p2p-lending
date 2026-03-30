<?php

namespace App\Filament\Resources\OriginatorResource\Pages;

use App\Filament\Resources\OriginatorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOriginators extends ListRecords
{
    protected static string $resource = OriginatorResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
