<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A tag received into the Negosyo Center stock with this UID. */
function stockTag(string $uid = '04A1B2C3D4E5F6'): NfcTag
{
    return NfcTag::factory()->inStock()->create(['uid' => $uid]);
}

it('normalizes UIDs', function () {
    expect(NfcTag::normalizeUid('04:a1 b2-c3'))->toBe('04A1B2C3');
});

it('lets staff link a tag from the stock to an active product', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    stockTag();
    Sanctum::actingAs($staff);

    $this->postJson('/api/nfc-tags', [
        'uid' => '04:a1:b2:c3:d4:e5:f6',
        'product_id' => $product->id,
    ])
        ->assertCreated()
        ->assertJsonPath('tag.uid', '04A1B2C3D4E5F6')
        ->assertJsonPath('tag.status', 'active')
        ->assertJsonPath('tag.product.id', $product->id)
        ->assertJsonPath('tag.association.id', $product->association_id);

    $this->assertDatabaseHas('nfc_tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
        'association_id' => $product->association_id,
        'status' => 'active',
        'issued_by' => $staff->id,
        'sent_by' => $staff->id,
    ]);
    $this->assertDatabaseHas('nfc_tag_events', ['action' => 'linked', 'to_product_id' => $product->id, 'user_id' => $staff->id]);
});

it('lets staff link a tag already sent to the product association', function () {
    $product = Product::factory()->create();
    NfcTag::factory()->sentTo($product->association)->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])->assertCreated();
});

it('refuses a tag that was sent to a different association', function () {
    $product = Product::factory()->create();
    NfcTag::factory()->sentTo(Association::factory()->create())->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');

    expect(NfcTag::firstOrFail()->status)->toBe('sent');
});

it('refuses a tag that is not in the inventory', function () {
    $product = Product::factory()->create();
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');

    expect(NfcTag::count())->toBe(0);
});

it('refuses a tag marked damaged or lost', function () {
    $product = Product::factory()->create();
    NfcTag::factory()->inStock()->create(['uid' => '04A1B2C3D4E5F6', 'status' => 'damaged']);
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');
});

it('does not let the administrator link a tag', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();
    stockTag();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
    ])->assertForbidden();

    expect(NfcTag::firstOrFail()->status)->toBe('in_stock');
});

it('requires authentication for tag management', function () {
    $this->getJson('/api/nfc-tags')->assertUnauthorized();
    $this->postJson('/api/nfc-tags', [])->assertUnauthorized();
});

it('blocks a deactivated staff account even with a valid token', function () {
    Sanctum::actingAs(User::factory()->staff()->inactive()->create());

    $this->getJson('/api/nfc-tags')
        ->assertForbidden()
        ->assertJsonPath('message', 'This account is inactive.');
});

it('rejects a UID that is already linked', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $existing = NfcTag::factory()->create();
    $product = Product::factory()->create();

    $this->postJson('/api/nfc-tags', [
        'uid' => $existing->uid,
        'product_id' => $product->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('uid');
});

it('rejects a product that already has an active tag', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $existing = NfcTag::factory()->create();
    stockTag();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $existing->product_id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_id');
});

it('allows a replacement tag after the old one is revoked', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $old = NfcTag::factory()->revoked()->create();
    stockTag();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $old->product_id,
    ])->assertCreated();
});

it('refuses to link a revoked tag again', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $old = NfcTag::factory()->revoked()->create();
    $product = Product::factory()->create();

    $this->postJson('/api/nfc-tags', ['uid' => $old->uid, 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');
});

it('rejects an inactive product', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $product = Product::factory()->inactive()->create();
    stockTag();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_id');
});

it('rejects a product whose association is inactive', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $association = Association::factory()->create(['status' => 'inactive']);
    $product = Product::factory()->create(['association_id' => $association->id]);
    stockTag();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_id');
});

it('rejects a malformed UID and an unknown product', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/nfc-tags', [
        'uid' => 'not-a-uid',
        'product_id' => 999999,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['uid', 'product_id']);
});

it('lets staff list tags with their products and associations', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    NfcTag::factory()->count(2)->create();

    $this->getJson('/api/nfc-tags')
        ->assertOk()
        ->assertJsonCount(2, 'tags')
        ->assertJsonStructure([
            'tags' => [
                '*' => ['uid', 'status', 'product' => ['title'], 'association' => ['name']],
            ],
        ]);
});

it('revokes a tag, records it, and the next scan is flagged suspicious', function () {
    $staff = User::factory()->staff()->create();
    Sanctum::actingAs($staff);
    $tag = NfcTag::factory()->create();

    $this->patchJson("/api/nfc-tags/{$tag->id}/revoke")
        ->assertOk()
        ->assertJsonPath('tag.status', 'revoked');

    $this->assertDatabaseHas('nfc_tag_events', ['nfc_tag_id' => $tag->id, 'action' => 'revoked', 'user_id' => $staff->id]);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertJsonPath('result', 'suspicious');
});

it('lets the administrator revoke a tag after a report', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $this->patchJson("/api/nfc-tags/{$tag->id}/revoke")->assertOk()->assertJsonPath('tag.status', 'revoked');
});

it('does not revoke a tag that was never linked', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $tag = stockTag();

    $this->patchJson("/api/nfc-tags/{$tag->id}/revoke")->assertUnprocessable();

    expect($tag->refresh()->status)->toBe('in_stock');
});
