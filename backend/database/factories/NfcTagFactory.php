<?php

namespace Database\Factories;

use App\Models\NfcTag;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NfcTag>
 */
class NfcTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            // 7-byte UID, the usual length for NTAG tags.
            'uid' => strtoupper(bin2hex(random_bytes(7))),
            'product_id' => Product::factory(),
            'status' => 'active',
            'issued_by' => null,
            'issued_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'revoked',
        ]);
    }

    public function unlinked(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => null,
            'issued_at' => null,
        ]);
    }
}
