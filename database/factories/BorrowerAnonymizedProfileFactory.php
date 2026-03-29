<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\BorrowerAnonymizedProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BorrowerAnonymizedProfile> */
class BorrowerAnonymizedProfileFactory extends Factory
{
    protected $model = BorrowerAnonymizedProfile::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'risk_class' => fake()->randomElement(['A', 'B', 'C', 'D', 'E']),
            'region' => fake()->randomElement(['София', 'Пловдив', 'Варна', 'Бургас', 'Русе', 'Стара Загора']),
            'loan_purpose' => fake()->randomElement(['Потребителски нужди', 'Ремонт', 'Автомобил', 'Бизнес', 'Образование', 'Рефинансиране']),
            'collateral_type' => fake()->optional(0.4)->randomElement(['Недвижим имот', 'Автомобил', 'Поръчителство']),
            'age_group' => fake()->randomElement(['18-25', '26-35', '36-45', '46-55', '56-65']),
        ];
    }
}
