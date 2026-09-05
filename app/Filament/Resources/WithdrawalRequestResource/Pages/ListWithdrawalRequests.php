<?php

namespace App\Filament\Resources\WithdrawalRequestResource\Pages;

use App\Filament\Resources\WithdrawalRequestResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Locked;

class ListWithdrawalRequests extends ListRecords
{
    protected static string $resource = WithdrawalRequestResource::class;

    /** Seconds a successful password check keeps «IBAN за превод» openable on this page. */
    public const IBAN_REVEAL_GRANT_SECONDS = 120;

    /**
     * SEC-11 reveal grant — `['id' => withdrawal id, 'expires' => unix ts]`.
     *
     * `#[Locked]`: Livewire refuses any attempt from the browser to set it
     * (CannotUpdateLockedPropertyException), it lives only while this page is
     * open (closing the tab ends the grant) and it costs no cache round-trip
     * per rendered row — prod's CACHE_STORE=database would otherwise pay one
     * SELECT per visible row for a `Cache::has()` visibility rule.
     */
    #[Locked]
    public ?array $ibanRevealGrant = null;

    public function grantIbanReveal(int $withdrawalId): void
    {
        $this->ibanRevealGrant = [
            'id' => $withdrawalId,
            'expires' => now()->addSeconds(self::IBAN_REVEAL_GRANT_SECONDS)->getTimestamp(),
        ];
    }

    public function hasIbanRevealGrant(int $withdrawalId): bool
    {
        $grant = $this->ibanRevealGrant;

        return $grant !== null
            && (int) ($grant['id'] ?? 0) === $withdrawalId
            && (int) ($grant['expires'] ?? 0) > now()->getTimestamp();
    }

    public function clearIbanRevealGrant(): void
    {
        $this->ibanRevealGrant = null;
    }
}
