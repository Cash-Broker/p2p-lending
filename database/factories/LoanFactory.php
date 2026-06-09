<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Originator;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Loan> */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 50000);
        $interestRate = fake()->randomFloat(2, 6, 15);

        return [
            'originator_id' => Originator::factory(),
            'borrower_id' => Borrower::factory(),
            'amount' => $amount,
            // investable_amount intentionally left unset → Loan::investableAmount()
            // falls back to `amount`, so factory loans behave identically to
            // pre-cap. Tests that need a real cap set it explicitly.
            'funded_amount' => 0,
            'interest_rate' => $interestRate,
            // Borrower pays slightly more than investor earns — originator keeps the spread
            'interest_rate_annual' => bcadd($interestRate, fake()->randomFloat(2, 1, 3), 2),
            'term_months' => fake()->randomElement([3, 6, 9, 12, 18, 24]),
            'type' => fake()->randomElement(['consumer', 'business', 'mortgage', 'bridge']),
            'status' => 'draft',
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function funding(): static
    {
        return $this->state(fn () => [
            'status' => 'funding',
            'published_at' => now()->subDays(fake()->numberBetween(1, 14)),
        ]);
    }

    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'active',
                'funded_amount' => $attributes['amount'],
                'published_at' => now()->subMonths(1),
            ];
        });
    }

    public function repaid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'repaid',
                'funded_amount' => $attributes['amount'],
                'published_at' => now()->subMonths(6),
            ];
        });
    }
}
