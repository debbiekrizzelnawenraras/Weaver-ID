<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function assocProduct(Association $association, array $productState = []): Product
{
    return Product::factory()->create(array_merge(['association_id' => $association->id], $productState));
}

function scanOf(?NfcTag $tag, string $result, string $uid = '04AABBCCDDEEFF'): ScanLog
{
    return ScanLog::create([
        'uid' => $tag?->uid ?? $uid,
        'nfc_tag_id' => $tag?->id,
        'result' => $result,
        'device_id' => 'test-device',
    ]);
}

// ---- association scan logs -------------------------------------------------

it('shows an association only the scans of its own tags', function () {
    $user = User::factory()->association()->create();
    $own = NfcTag::factory()->create(['product_id' => assocProduct($user->association)->id]);
    $foreign = NfcTag::factory()->create();
    scanOf($own, 'authenticated');
    scanOf($foreign, 'authenticated');
    scanOf(null, 'unregistered');
    Sanctum::actingAs($user);

    $this->getJson('/api/association/scan-logs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uid', $own->uid);
});

it('filters association scan logs by result', function () {
    $user = User::factory()->association()->create();
    $tag = NfcTag::factory()->create(['product_id' => assocProduct($user->association)->id]);
    scanOf($tag, 'authenticated');
    scanOf($tag, 'suspicious');
    Sanctum::actingAs($user);

    $this->getJson('/api/association/scan-logs?result=suspicious')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.result', 'suspicious');

    $this->getJson('/api/association/scan-logs?result=bogus')->assertUnprocessable();
});

it('keeps association scan logs away from staff', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson('/api/association/scan-logs')->assertForbidden();
});

// ---- association product edit ---------------------------------------------

it('lets an association edit and deactivate its own product', function () {
    $user = User::factory()->association()->create();
    $product = assocProduct($user->association);
    Sanctum::actingAs($user);

    $this->putJson("/api/association/products/{$product->id}", [
        'title' => 'Renamed Blanket',
        'category' => 'Traditional Woven Fabric',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('product.status', 'inactive');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'Renamed Blanket', 'status' => 'inactive']);
});

it('stops an association editing another association product', function () {
    $user = User::factory()->association()->create();
    $foreign = assocProduct(Association::factory()->create());
    Sanctum::actingAs($user);

    $this->putJson("/api/association/products/{$foreign->id}", [
        'title' => 'Hijacked',
        'category' => 'Traditional Woven Fabric',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('products', ['id' => $foreign->id, 'title' => 'Hijacked']);
});

it('lets an association reactivate its own product', function () {
    $user = User::factory()->association()->create();
    $product = assocProduct($user->association, ['status' => 'inactive']);
    Sanctum::actingAs($user);

    $this->putJson("/api/association/products/{$product->id}", [
        'title' => $product->title,
        'category' => $product->category,
        'status' => 'active',
    ])->assertOk()->assertJsonPath('product.status', 'active');
});

it('lets an association add a product, owned by the association itself', function () {
    $user = User::factory()->association()->create();
    Sanctum::actingAs($user);

    $id = $this->postJson('/api/association/products', ['title' => 'Inabel Runner', 'category' => 'Traditional Woven Fabric'])
        ->assertCreated()
        ->json('product.id');

    expect(Product::findOrFail($id)->association_id)->toBe($user->association_id);
});

it('rejects an invalid product status', function () {
    $user = User::factory()->association()->create();
    $product = assocProduct($user->association);
    Sanctum::actingAs($user);

    $this->putJson("/api/association/products/{$product->id}", [
        'title' => $product->title,
        'category' => $product->category,
        'status' => 'deleted',
    ])->assertUnprocessable();
});

// ---- current user ----------------------------------------------------------

it('returns the association with the signed-in association user', function () {
    $user = User::factory()->association()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('role', 'association')
        ->assertJsonPath('association.name', $user->association->name);
});

it('has no member routes any more', function () {
    Sanctum::actingAs(User::factory()->association()->create());
    $this->getJson('/api/association/weavers')->assertNotFound();
    $this->postJson('/api/association/weavers', ['name' => 'Sample Member', 'municipality' => 'Sagada'])->assertNotFound();

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));
    $this->getJson('/api/staff/weavers')->assertNotFound();
});
