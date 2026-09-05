<?php

namespace Database\Factories;

use App\Models\SavedIban;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedIban>
 */
class SavedIbanFactory extends Factory
{
    protected $model = SavedIban::class;

    public function definition(): array
    {
        // Default: confirmed 25 h ago — outside the 24 h cooling-off (SEC-01).
        return [
            'user_id' => User::factory(),
            'iban' => 'BG80BNBG96611020345678',
            'label' => 'Основна',
            'confirmed_at' => now()->subHours(25),
            'confirmation_token_hash' => null,
            'confirmation_sent_at' => now()->subHours(26),
            'confirmation_expires_at' => null,
        ];
    }

    /** A freshly added IBAN whose owner has not clicked the e-mail link yet. */
    public function unconfirmed(string $plainToken = 'factory-token'): static
    {
        return $this->state(fn () => [
            'confirmed_at' => null,
            'confirmation_token_hash' => hash('sha256', $plainToken),
            'confirmation_sent_at' => now(),
            'confirmation_expires_at' => now()->addMinutes(SavedIban::CONFIRMATION_TTL_MINUTES),
        ]);
    }

    public function confirmed(?CarbonInterface $at = null): static
    {
        $at ??= now()->subHours(25);

        return $this->state(fn () => [
            'confirmed_at' => $at,
            'confirmation_token_hash' => null,
            'confirmation_sent_at' => $at->copy()->subMinutes(5),
            'confirmation_expires_at' => null,
        ]);
    }

    /** A row from before the feature: no token was ever issued. */
    public function legacy(): static
    {
        return $this->state(fn () => [
            'confirmed_at' => null,
            'confirmation_token_hash' => null,
            'confirmation_sent_at' => null,
            'confirmation_expires_at' => null,
        ]);
    }
}
