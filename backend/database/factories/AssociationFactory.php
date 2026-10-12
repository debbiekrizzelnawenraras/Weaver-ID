<?php

namespace Database\Factories;

use App\Models\Association;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Association>
 */
class AssociationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Weavers',
            'municipality' => fake()->randomElement(['Bontoc', 'Sagada', 'Besao', 'Bauko', 'Tadian']),
            'status' => 'active',
        ];
    }
}
