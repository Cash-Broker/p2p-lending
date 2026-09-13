<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AccruedEarningsService;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * «Начислени лихви» / «Текущ баланс» support: the record's accrued
     * interest, computed ONCE per render and shared by both entries — two
     * separate `now()` instants could straddle midnight and the two figures
     * would stop adding up. A request-scoped memo, never a Livewire property
     * (see ListUsers).
     *
     * @var array<int, string>
     */
    private array $accruedInterestByUser = [];

    public function accruedInterestFor(User $user): string
    {
        return $this->accruedInterestByUser[$user->id] ??= app(AccruedEarningsService::class)
            ->accruedByUser([$user->id])[$user->id] ?? '0.00';
    }

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
            UserResource::cancelDeletionAction(),
            UserResource::kycArchiveAction(),
        ];
    }
}
