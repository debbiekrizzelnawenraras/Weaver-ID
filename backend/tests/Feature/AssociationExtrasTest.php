<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function assocProduct(Association $association, array $weaverState = [], array $productState = []): Product
{
    $weaver = Weaver::factory()->create(array_merge(['association_id' => $association->id], $weaverState));

    return Product::factory()->create(array_merge(['weaver_id' => $weaver->id], $productState));
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
        'category' => 'Blanket',
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
        'category' => 'Blanket',
        'status' => 'active',
    ])->assertForbidden();

    $this->assertDatabaseMissing('products', ['id' => $foreign->id, 'title' => 'Hijacked']);
});

it('refuses to reactivate a product whose weaver is inactive', function () {
    $user = User::factory()->association()->create();
    $product = assocProduct($user->association, ['status' => 'inactive'], ['status' => 'inactive']);
    Sanctum::actingAs($user);

    $this->putJson("/api/association/products/{$product->id}", [
        'title' => $product->title,
        'category' => $product->category,
        'status' => 'active',
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
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
