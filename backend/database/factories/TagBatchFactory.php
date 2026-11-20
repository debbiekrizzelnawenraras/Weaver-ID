<?php

namespace Database\Factories;

use App\Models\TagBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TagBatch>
 */
class TagBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'B-'.strtoupper(fake()->unique()->bothify('####??')),
            // Same default municipality as the user and association factories.
            'municipality' => 'Bontoc',
            'received_on' => now()->toDateString(),
        ];
    }
}
