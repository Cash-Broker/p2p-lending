<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WithdrawalRequest> */
class WithdrawalRequestFactory extends Factory
{
    protected $model = WithdrawalRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'iban' => fake()->iban('BG'),
            'status' => 'pending',
            'admin_note' => null,
            'processed_at' => null,
        ];
    }
}
