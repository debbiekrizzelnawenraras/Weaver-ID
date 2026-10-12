<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Weaver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'weaver_id' => Weaver::factory(),
            'title' => fake()->randomElement(['Pinagpagan Pattern Blanket', 'Inabel Table Runner', 'Woven Tote Bag']),
            'category' => fake()->randomElement(['Blanket / Textile', 'Home Decor', 'Bags & Accessories']),
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
