<?php

namespace Database\Factories;

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\TagBatch;
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
            // A linked tag was sent to its product's association.
            'association_id' => fn (array $attributes) => $attributes['product_id']
                ? Product::find($attributes['product_id'])?->association_id
                : null,
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

    /** A tag in a Negosyo Center's stock (Bontoc by default): received, not sent, not linked. */
    public function unlinked(string $municipality = 'Bontoc'): static
    {
        return $this->state(fn (array $attributes) => [
            'tag_batch_id' => TagBatch::factory()->state(['municipality' => $municipality]),
            'product_id' => null,
            'association_id' => null,
            'status' => 'in_stock',
            'issued_at' => null,
        ]);
    }

    public function inStock(string $municipality = 'Bontoc'): static
    {
        return $this->unlinked($municipality);
    }

    /** A tag sent to an association and not linked yet. */
    public function sentTo(Association $association): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => null,
            'association_id' => $association->id,
            'status' => 'sent',
            'sent_at' => now(),
            'issued_at' => null,
        ]);
    }
}
