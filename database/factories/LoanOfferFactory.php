<?php

namespace Database\Factories;

use App\Enums\PayoutType;
use App\Models\Loan;
use App\Models\LoanOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoanOffer> */
class LoanOfferFactory extends Factory
{
    protected $model = LoanOffer::class;

    public function definition(): array
    {
        $type = fake()->randomElement(PayoutType::cases());

        return [
            'loan_id' => Loan::factory(),
            'payout_type' => $type,
            'interest_rate' => $type->defaultRate(),
            'is_enabled' => true,
            'position' => $type->position(),
        ];
    }

    public function type(PayoutType $type): static
    {
        return $this->state(fn () => [
            'payout_type' => $type,
            'interest_rate' => $type->defaultRate(),
            'position' => $type->position(),
        ]);
    }
}
