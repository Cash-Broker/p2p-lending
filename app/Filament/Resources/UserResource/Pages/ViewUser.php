<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The reviewer approves/rejects WHILE looking at the documents — the
     * same actions as the table rows, pinned to the page header. The
     * bonus grant lives here too (needs the full profile in view).
     */
    protected function getHeaderActions(): array
    {
        return [
            ...UserResource::kycStatusActions(),
            UserResource::bonusAction(),
            UserResource::phoneAction(),
        ];
    }
}
