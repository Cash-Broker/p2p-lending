<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    /**
     * Straight to the edit page after creation — the three auto-seeded
     * offers (the real pricing) live there, so the admin lands on «Оферти
     * към инвеститорите» and KNOWS the loan is in (boss 2026-08-10).
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Кредитът е създаден')
            ->body('Трите оферти към инвеститорите са готови по-долу — прегледай лихвите им и запази.');
    }
}
