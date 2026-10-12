<?php

use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('normalizes UIDs', function () {
    expect(NfcTag::normalizeUid('04:a1 b2-c3'))->toBe('04A1B2C3');
});

it('lets staff issue a tag for an active product', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    Sanctum::actingAs($staff);

    $this->postJson('/api/nfc-tags', [
        'uid' => '04:a1:b2:c3:d4:e5:f6',
        'product_id' => $product->id,
    ])
        ->assertCreated()
        ->assertJsonPath('tag.uid', '04A1B2C3D4E5F6')
        ->assertJsonPath('tag.status', 'active')
        ->assertJsonPath('tag.product.id', $product->id);

    $this->assertDatabaseHas('nfc_tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
        'status' => 'active',
        'issued_by' => $staff->id,
    ]);
});

it('lets admin issue a tag', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
    ])->assertCreated();
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

it('rejects a duplicate UID', function () {
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

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $old->product_id,
    ])->assertCreated();
});

it('rejects an inactive product', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $product = Product::factory()->inactive()->create();

    $this->postJson('/api/nfc-tags', [
        'uid' => '04A1B2C3D4E5F6',
        'product_id' => $product->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_id');
});

it('rejects a product whose weaver is inactive', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $weaver = Weaver::factory()->inactive()->create();
    $product = Product::factory()->create(['weaver_id' => $weaver->id]);

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

it('lets staff list tags with their products', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    NfcTag::factory()->count(2)->create();

    $this->getJson('/api/nfc-tags')
        ->assertOk()
        ->assertJsonCount(2, 'tags')
        ->assertJsonStructure([
            'tags' => [
                '*' => ['uid', 'status', 'product' => ['title', 'weaver' => ['association']]],
            ],
        ]);
});

it('revokes a tag and the next scan is flagged suspicious', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $tag = NfcTag::factory()->create();

    $this->patchJson("/api/nfc-tags/{$tag->id}/revoke")
        ->assertOk()
        ->assertJsonPath('tag.status', 'revoked');

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertJsonPath('result', 'suspicious');
});
