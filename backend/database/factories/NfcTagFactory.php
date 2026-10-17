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

    /**
     * A tag the desk has secured: write password set, read counter on.
     */
    public function secured(int $counter = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'counter_enabled' => true,
            'last_counter' => $counter,
            'secured_at' => now(),
        ]);
    }

    /**
     * A secured tag that also carries the tag code (scans must send it).
     */
    public function withCode(int $counter = 10): static
    {
        return $this->secured($counter)->state(fn (array $attributes) => [
            'code_enabled' => true,
        ]);
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
