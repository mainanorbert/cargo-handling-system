<?php

namespace Database\Factories;

use App\Models\Truck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Truck>
 */
class TruckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration' => fake()->unique()->bothify('K?? ###?'),
            'can_plate' => fake()->unique()->bothify('CAB ###?'),
            'trail_plate' => fake()->optional()->bothify('TRL ###?'),
        ];
    }
}
