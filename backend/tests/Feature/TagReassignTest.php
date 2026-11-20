<?php

use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use App\Models\Association;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('keeps reassigning away from staff and association users', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());
    $tag = NfcTag::factory()->create();
    $product = Product::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $product->id])
        ->assertForbidden();
})->with(['staff', 'association']);

it('moves an active tag to another product and records who did it', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);
    $tag = NfcTag::factory()->create();
    $oldProductId = $tag->product_id;
    $target = Product::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('tag.product_id', $target->id)
        ->assertJsonPath('tag.status', 'active');

    $this->assertDatabaseHas('nfc_tag_events', [
        'nfc_tag_id' => $tag->id,
        'user_id' => $admin->id,
        'action' => 'reassigned',
        'from_product_id' => $oldProductId,
        'to_product_id' => $target->id,
    ]);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertJsonPath('result', 'authenticated')
        ->assertJsonPath('product.title', $target->title);
});

it('rejects a product that already has an active tag', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();
    $taken = NfcTag::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $taken->product_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_id');
});

it('rejects an inactive product, an inactive association and the same product', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $inactiveProduct = Product::factory()->inactive()->create();
    $inactiveAssociation = Product::factory()->create(['association_id' => Association::factory()->create(['status' => 'inactive'])->id]);

    foreach ([$inactiveProduct->id, $inactiveAssociation->id, $tag->product_id] as $productId) {
        $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $productId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }
});

it('keeps a revoked tag revoked unless reactivate is sent', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->revoked()->create();
    $target = Product::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reactivate');

    expect($tag->fresh()->status)->toBe('revoked');
});

it('reactivates a revoked tag when asked and logs it', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);
    $tag = NfcTag::factory()->revoked()->create();
    $target = Product::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", [
        'product_id' => $target->id,
        'reactivate' => true,
    ])
        ->assertOk()
        ->assertJsonPath('tag.status', 'active');

    $this->assertDatabaseHas('nfc_tag_events', [
        'nfc_tag_id' => $tag->id,
        'action' => 'reactivated',
        'to_product_id' => $target->id,
    ]);

    $this->postJson('/api/verify', ['uid' => $tag->uid])->assertJsonPath('result', 'authenticated');
});

it('does not link a tag from the stock, because linking is staff work', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->unlinked()->create();
    $target = Product::factory()->create();

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $target->id])
        ->assertUnprocessable();

    expect($tag->fresh()->status)->toBe('in_stock');
});

it('moves the tag to the association of its new product', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();
    $target = Product::factory()->create(['association_id' => Association::factory()->create()->id]);

    $this->patchJson("/api/admin/nfc-tags/{$tag->id}/reassign", ['product_id' => $target->id])->assertOk();

    expect($tag->fresh()->association_id)->toBe($target->association_id);
});
