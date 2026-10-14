<?php

namespace Database\Factories;

use App\Models\Association;
use App\Models\Weaver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Weaver>
 */
class WeaverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'association_id' => Association::factory(),
            'name' => fake()->lastName() . ' Weaving',
            'municipality' => fake()->randomElement(['Bontoc', 'Sagada', 'Besao', 'Bauko', 'Tadian']),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
