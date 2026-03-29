<?php

namespace Database\Factories;

use App\Models\Borrower;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Borrower> */
class BorrowerFactory extends Factory
{
    protected $model = Borrower::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'personal_id' => fake()->numerify('##########'), // Will be encrypted by model cast
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'income' => fake()->randomFloat(2, 800, 8000),
            'credit_score' => fake()->optional(0.7)->numberBetween(300, 850),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
