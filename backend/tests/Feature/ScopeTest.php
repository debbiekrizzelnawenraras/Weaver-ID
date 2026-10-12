<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A product inside the given association. */
function productIn(Association $association, array $productState = []): Product
{
    $weaver = Weaver::factory()->create(['association_id' => $association->id]);

    return Product::factory()->create(array_merge(['weaver_id' => $weaver->id], $productState));
}

// ---- tag linking: association users ---------------------------------------

it('lets an association user issue a tag for its own product', function () {
    $user = User::factory()->association()->create();
    $product = productIn($user->association);
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertCreated();

    $this->assertDatabaseHas('nfc_tags', ['uid' => '04A1B2C3D4E5F6', 'issued_by' => $user->id]);
});

it('stops an association user issuing a tag for another association', function () {
    $user = User::factory()->association()->create();
    $foreign = productIn(Association::factory()->create());
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $foreign->id])
        ->assertForbidden();

    $this->assertDatabaseCount('nfc_tags', 0);
});

it('lists only the tags of the association', function () {
    $user = User::factory()->association()->create();
    $own = NfcTag::factory()->create(['product_id' => productIn($user->association)->id]);
    NfcTag::factory()->create(); // another association
    Sanctum::actingAs($user);

    $this->getJson('/api/nfc-tags')
        ->assertOk()
        ->assertJsonCount(1, 'tags')
        ->assertJsonPath('tags.0.id', $own->id);
});

it('stops an association user revoking a foreign tag', function () {
    $user = User::factory()->association()->create();
    $foreign = NfcTag::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson("/api/nfc-tags/{$foreign->id}/revoke")->assertForbidden();

    expect($foreign->fresh()->status)->toBe('active');
});

it('lets an association user revoke its own tag', function () {
    $user = User::factory()->association()->create();
    $own = NfcTag::factory()->create(['product_id' => productIn($user->association)->id]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/nfc-tags/{$own->id}/revoke")->assertOk();

    expect($own->fresh()->status)->toBe('revoked');
});

// ---- tag linking: staff municipality --------------------------------------

it('limits staff to tags in their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $inScope = NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Sagada']))->id,
    ]);
    NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Besao']))->id,
    ]);
    Sanctum::actingAs($staff);

    $this->getJson('/api/nfc-tags')
        ->assertOk()
        ->assertJsonCount(1, 'tags')
        ->assertJsonPath('tags.0.id', $inScope->id);
});

it('stops staff issuing or revoking tags outside their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $foreignProduct = productIn(Association::factory()->create(['municipality' => 'Besao']));
    $foreignTag = NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Besao']))->id,
    ]);
    Sanctum::actingAs($staff);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $foreignProduct->id])
        ->assertForbidden();
    $this->patchJson("/api/nfc-tags/{$foreignTag->id}/revoke")->assertForbidden();
});

it('treats staff without a municipality as having no scope', function () {
    $staff = User::factory()->staff()->create(['municipality' => null]);
    NfcTag::factory()->create();
    Sanctum::actingAs($staff);

    $this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(0, 'tags');
    $this->getJson('/api/staff/weavers')->assertOk()->assertJsonCount(0, 'weavers');
});

it('lets admin see and issue tags everywhere', function () {
    NfcTag::factory()->count(2)->create();
    $product = productIn(Association::factory()->create(['municipality' => 'Tadian']));
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(2, 'tags');
    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertCreated();
});

// ---- staff registry management --------------------------------------------

it('limits staff weavers and products to their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $own = productIn(Association::factory()->create(['municipality' => 'Sagada']));
    productIn(Association::factory()->create(['municipality' => 'Besao']));
    Sanctum::actingAs($staff);

    $this->getJson('/api/staff/weavers')->assertOk()->assertJsonCount(1, 'weavers');
    $this->getJson('/api/staff/products')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $own->id);
    $this->getJson('/api/staff/associations')->assertOk()->assertJsonCount(1, 'associations');
});

it('stops staff editing a weaver or product outside their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $foreign = productIn(Association::factory()->create(['municipality' => 'Besao']));
    Sanctum::actingAs($staff);

    $this->putJson("/api/staff/products/{$foreign->id}", [
        'weaver_id' => $foreign->weaver_id, 'title' => 'x', 'category' => 'y', 'status' => 'active',
    ])->assertForbidden();

    $this->putJson("/api/staff/weavers/{$foreign->weaver_id}", [
        'association_id' => $foreign->weaver->association_id,
        'name' => 'x', 'proprietor' => 'y', 'municipality' => 'Besao', 'status' => 'active',
    ])->assertForbidden();
});

it('stops staff adding a weaver to a foreign association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/weavers', [
        'association_id' => $foreign->id, 'name' => 'x', 'proprietor' => 'y', 'municipality' => 'Besao',
    ])->assertForbidden();
});

it('lets staff register an association only in their own municipality', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));

    $this->postJson('/api/staff/associations', ['name' => 'Own Weavers', 'municipality' => 'sagada'])
        ->assertCreated();
    $this->postJson('/api/staff/associations', ['name' => 'Foreign Weavers', 'municipality' => 'Besao'])
        ->assertForbidden();
});

it('returns 422, not 403, when adding a product to an inactive weaver', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $weaver = Weaver::factory()->inactive()->create();
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/products', ['weaver_id' => $weaver->id, 'title' => 'x', 'category' => 'y'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('weaver_id');
});

it('lets staff deactivate a product of an inactive weaver but not reactivate it', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $weaver = Weaver::factory()->inactive()->create();
    $product = Product::factory()->create(['weaver_id' => $weaver->id]);
    Sanctum::actingAs($staff);

    $payload = ['weaver_id' => $weaver->id, 'title' => 'x', 'category' => 'y'];

    $this->putJson("/api/staff/products/{$product->id}", $payload + ['status' => 'inactive'])->assertOk();
    $this->putJson("/api/staff/products/{$product->id}", $payload + ['status' => 'active'])
        ->assertStatus(422);
});

it('blocks association users from the staff routes', function () {
    Sanctum::actingAs(User::factory()->association()->create());

    $this->getJson('/api/staff/weavers')->assertForbidden();
});

// ---- association status filter --------------------------------------------

it('lists active weavers by default and inactive ones on request', function () {
    $user = User::factory()->association()->create();
    Weaver::factory()->create(['association_id' => $user->association_id]);
    Weaver::factory()->inactive()->create(['association_id' => $user->association_id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/association/weavers')->assertJsonCount(1, 'weavers');
    $this->getJson('/api/association/weavers?status=inactive')->assertJsonCount(1, 'weavers');
    $this->getJson('/api/association/weavers?status=all')->assertJsonCount(2, 'weavers');
    $this->getJson('/api/association/weavers?status=bogus')->assertStatus(422);
});

it('lists inactive products of the association on request', function () {
    $user = User::factory()->association()->create();
    productIn($user->association);
    productIn($user->association, ['status' => 'inactive']);
    Sanctum::actingAs($user);

    $this->getJson('/api/association/products')->assertJsonCount(1, 'products');
    $this->getJson('/api/association/products?status=all')->assertJsonCount(2, 'products');
});

it('returns 422 when an association user adds a product to its inactive weaver', function () {
    $user = User::factory()->association()->create();
    $weaver = Weaver::factory()->inactive()->create(['association_id' => $user->association_id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/association/products', ['weaver_id' => $weaver->id, 'title' => 'x', 'category' => 'y'])
        ->assertStatus(422);
});
