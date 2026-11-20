<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Association;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'association_id' => Association::factory(),
            'title' => fake()->randomElement(['Pinagpagan Pattern Blanket', 'Inabel Table Runner', 'Woven Tote Bag']),
            'category' => fake()->randomElement(\App\Models\Product::CATEGORIES),
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
