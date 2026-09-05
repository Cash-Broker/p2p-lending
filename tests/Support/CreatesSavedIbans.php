<?php

namespace Tests\Support;

use App\Models\SavedIban;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * SEC-01 (owner 2026-09-03): withdrawals go only to a CONFIRMED saved IBAN past
 * the cooling-off. Tests that need a payout destination build one here instead
 * of posting a raw IBAN.
 */
trait CreatesSavedIbans
{
    protected function confirmedIban(User $user, string $iban = 'BG80BNBG96611020345678', ?CarbonInterface $confirmedAt = null): SavedIban
    {
        $at = $confirmedAt !== null ? CarbonImmutable::instance($confirmedAt) : now()->subHours(25)->toImmutable();

        return SavedIban::factory()->for($user)->create([
            'iban' => $iban,
            'confirmed_at' => $at,
            'confirmation_token_hash' => null,
            'confirmation_sent_at' => $at->subMinutes(5),
            'confirmation_expires_at' => null,
        ]);
    }

    protected function unconfirmedIban(User $user, string $iban = 'BG80BNBG96611020345678', string $plainToken = 'factory-token'): SavedIban
    {
        return SavedIban::factory()->for($user)->unconfirmed($plainToken)->create(['iban' => $iban]);
    }

    protected function legacyIban(User $user, string $iban = 'BG80BNBG96611020345678'): SavedIban
    {
        return SavedIban::factory()->for($user)->legacy()->create(['iban' => $iban]);
    }
}
