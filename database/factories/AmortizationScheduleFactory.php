<?php

namespace Database\Factories;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AmortizationSchedule> */
class AmortizationScheduleFactory extends Factory
{
    protected $model = AmortizationSchedule::class;

    public function definition(): array
    {
        $principal = fake()->randomFloat(2, 100, 2000);
        $interest = fake()->randomFloat(2, 10, 200);

        return [
            'loan_id' => Loan::factory(),
            'due_date' => fake()->dateTimeBetween('now', '+12 months'),
            'principal' => $principal,
            'interest' => $interest,
            'total' => bcadd($principal, $interest, 2),
            'status' => 'pending',
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
