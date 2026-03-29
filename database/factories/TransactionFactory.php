<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['deposit', 'withdrawal', 'investment', 'repayment_principal', 'repayment_interest', 'fee']),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'description' => fake()->optional(0.5)->sentence(),
            'reference' => null,
        ];
    }
}
