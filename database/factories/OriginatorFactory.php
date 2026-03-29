<?php

namespace Database\Factories;

use App\Models\Originator;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Originator> */
class OriginatorFactory extends Factory
{
    protected $model = Originator::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'description' => fake()->paragraph(),
            'website' => fake()->url(),
            'buyback' => fake()->boolean(60),
            'logo_path' => null,
        ];
    }
}
