<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A product inside the given association. */
function productIn(Association $association, array $productState = []): Product
{
    return Product::factory()->create(array_merge(['association_id' => $association->id], $productState));
}

// ---- tag linking: association users ---------------------------------------

it('lets an association user link a tag sent to it, to its own product', function () {
    $user = User::factory()->association()->create();
    $product = productIn($user->association);
    NfcTag::factory()->sentTo($user->association)->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertCreated();

    $this->assertDatabaseHas('nfc_tags', ['uid' => '04A1B2C3D4E5F6', 'issued_by' => $user->id, 'status' => 'active']);
});

it('stops an association user linking a tag still in a Negosyo Center stock', function () {
    $user = User::factory()->association()->create();
    $product = productIn($user->association);
    NfcTag::factory()->inStock()->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');

    expect(NfcTag::firstOrFail()->status)->toBe('in_stock');
});

it('stops an association user linking a tag sent to another association', function () {
    $user = User::factory()->association()->create();
    $product = productIn($user->association);
    NfcTag::factory()->sentTo(Association::factory()->create())->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uid');
});

it('stops an association user issuing a tag for another association', function () {
    $user = User::factory()->association()->create();
    $foreign = productIn(Association::factory()->create());
    NfcTag::factory()->sentTo($user->association)->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs($user);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $foreign->id])
        ->assertForbidden();

    expect(NfcTag::firstOrFail()->status)->toBe('sent');
});

it('lists only the tags of the association, including tags sent but not linked', function () {
    $user = User::factory()->association()->create();
    $own = NfcTag::factory()->create(['product_id' => productIn($user->association)->id]);
    $waiting = NfcTag::factory()->sentTo($user->association)->create();
    NfcTag::factory()->create(); // another association
    NfcTag::factory()->inStock()->create(); // a Negosyo Center's stock
    Sanctum::actingAs($user);

    $ids = collect($this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(2, 'tags')->json('tags'))->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$own->id, $waiting->id])->sort()->values()->all());
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

it('keeps the inventory away from association users', function () {
    Sanctum::actingAs(User::factory()->association()->create());

    $this->getJson('/api/inventory')->assertForbidden();
    $this->postJson('/api/inventory/send', [])->assertForbidden();
});

// ---- tag linking: staff municipality --------------------------------------

it('limits staff to tags in their municipality plus their own Negosyo Center stock', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $inScope = NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Sagada']))->id,
    ]);
    NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Besao']))->id,
    ]);
    $stock = NfcTag::factory()->inStock('Sagada')->create();
    NfcTag::factory()->inStock('Besao')->create(); // another center's stock
    Sanctum::actingAs($staff);

    $ids = collect($this->getJson('/api/nfc-tags?include=stock')->assertOk()->assertJsonCount(2, 'tags')->json('tags'))->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$inScope->id, $stock->id])->sort()->values()->all());
    $this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(1, 'tags');
});

it('stops staff issuing or revoking tags outside their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $foreignProduct = productIn(Association::factory()->create(['municipality' => 'Besao']));
    $foreignTag = NfcTag::factory()->create([
        'product_id' => productIn(Association::factory()->create(['municipality' => 'Besao']))->id,
    ]);
    NfcTag::factory()->inStock()->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $foreignProduct->id])
        ->assertForbidden();
    $this->patchJson("/api/nfc-tags/{$foreignTag->id}/revoke")->assertForbidden();
});

it('treats staff without a municipality as having no scope', function () {
    $staff = User::factory()->staff()->create(['municipality' => null]);
    NfcTag::factory()->create();
    productIn(Association::factory()->create());
    Sanctum::actingAs($staff);

    $this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(0, 'tags');
    $this->getJson('/api/staff/products')->assertOk()->assertJsonCount(0, 'products');
});

it('lets admin see every tag but not link one', function () {
    NfcTag::factory()->count(2)->create();
    $product = productIn(Association::factory()->create(['municipality' => 'Tadian']));
    NfcTag::factory()->inStock()->create(['uid' => '04A1B2C3D4E5F6']);
    Sanctum::actingAs(User::factory()->admin()->create());

    // Stock is left out of the NFC Tags list unless asked for (it lives in the Inventory tab).
    $this->getJson('/api/nfc-tags')->assertOk()->assertJsonCount(2, 'tags');
    $this->getJson('/api/nfc-tags?include=stock')->assertOk()->assertJsonCount(3, 'tags');
    $this->postJson('/api/nfc-tags', ['uid' => '04A1B2C3D4E5F6', 'product_id' => $product->id])
        ->assertForbidden();
});

// ---- staff registry management --------------------------------------------

it('limits staff products and associations to their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $own = productIn(Association::factory()->create(['municipality' => 'Sagada']));
    productIn(Association::factory()->create(['municipality' => 'Besao']));
    Sanctum::actingAs($staff);

    $this->getJson('/api/staff/products')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $own->id);
    $this->getJson('/api/staff/associations')->assertOk()->assertJsonCount(1, 'associations');
});

it('stops staff editing a product outside their municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $foreign = productIn(Association::factory()->create(['municipality' => 'Besao']));
    Sanctum::actingAs($staff);

    $this->putJson("/api/staff/products/{$foreign->id}", [
        'association_id' => $foreign->association_id, 'title' => 'x', 'category' => 'Traditional Woven Fabric', 'status' => 'active',
    ])->assertForbidden();
});

it('stops staff moving a product into a foreign association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $own = productIn(Association::factory()->create(['municipality' => 'Sagada']));
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    Sanctum::actingAs($staff);

    $this->putJson("/api/staff/products/{$own->id}", [
        'association_id' => $foreign->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric', 'status' => 'active',
    ])->assertForbidden();
});

it('does not move a product with a tag to another association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Sagada']);
    $product = productIn(Association::factory()->create(['municipality' => 'Sagada']));
    NfcTag::factory()->create(['product_id' => $product->id]);
    $other = Association::factory()->create(['municipality' => 'Sagada']);
    Sanctum::actingAs($staff);

    $this->putJson("/api/staff/products/{$product->id}", [
        'association_id' => $other->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric', 'status' => 'active',
    ])->assertUnprocessable()->assertJsonValidationErrors('association_id');
});

it('files a staff association in their own municipality, whatever municipality is sent', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));

    $this->postJson('/api/staff/associations', [
        'association_name' => 'Own Weavers', 'municipality' => 'Besao', 'contact_name' => 'Ana Bagto',
        'contact_phone' => '09171234567', 'email' => 'own@example.test', 'password' => 'Loom-Thread-2026!q', 'privacy_consent' => true,
    ])->assertCreated()->assertJsonPath('application.municipality', 'Sagada');
});

it('shows staff only the applications of their own municipality', function () {
    foreach (['Sagada', 'Besao'] as $i => $municipality) {
        \App\Models\AssociationApplication::create([
            'reference' => 'AP-20261120-S000'.$i, 'association_name' => $municipality.' Group', 'municipality' => $municipality,
            'contact_name' => 'Contact', 'contact_phone' => '09171234567', 'email' => "c{$i}@example.test",
            'password_hash' => 'x', 'status' => 'pending', 'consented_at' => now(), 'notice_version' => '2026-10',
        ]);
    }
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));

    $this->getJson('/api/staff/association-applications')->assertOk()->assertJsonCount(1, 'applications');
});

it('lets staff deactivate a product of an inactive association but not reactivate it', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $association = Association::factory()->create(['status' => 'inactive']);
    $product = productIn($association);
    Sanctum::actingAs($staff);

    $payload = ['association_id' => $association->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric'];

    $this->putJson("/api/staff/products/{$product->id}", $payload + ['status' => 'inactive'])->assertOk();
    $this->putJson("/api/staff/products/{$product->id}", $payload + ['status' => 'active'])
        ->assertStatus(422);
});

// ---- association status filter --------------------------------------------

it('lists inactive products of the association on request', function () {
    $user = User::factory()->association()->create();
    productIn($user->association);
    productIn($user->association, ['status' => 'inactive']);
    Sanctum::actingAs($user);

    $this->getJson('/api/association/products')->assertJsonCount(1, 'products');
    $this->getJson('/api/association/products?status=inactive')->assertJsonCount(1, 'products');
    $this->getJson('/api/association/products?status=all')->assertJsonCount(2, 'products');
    $this->getJson('/api/association/products?status=bogus')->assertStatus(422);
});

it('blocks association users from every staff route', function () {
    Sanctum::actingAs(User::factory()->association()->create());

    $product = Product::factory()->create();

    $routes = [
        ['getJson',  '/api/staff/associations'],
        ['postJson', '/api/staff/associations'],
        ['getJson',  '/api/staff/products'],
        ['postJson', '/api/staff/products'],
        ['putJson',  "/api/staff/products/{$product->id}"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url, [])->assertForbidden();
    }
});

it('lets staff users reach the staff list routes', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson('/api/staff/associations')->assertOk();
    $this->getJson('/api/staff/products')->assertOk();
    $this->getJson('/api/inventory')->assertOk();
});
