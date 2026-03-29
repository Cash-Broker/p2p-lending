<?php

namespace Database\Factories;

use App\Models\Investment;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Investment> */
class InvestmentFactory extends Factory
{
    protected $model = Investment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'loan_id' => Loan::factory(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'invested_at' => now(),
        ];
    }
}
