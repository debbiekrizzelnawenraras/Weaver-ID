<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ProductPhotoEvent;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

// Same raw-byte JPEG as ProductPhotoTest (no GD needed).
function logJpeg(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
    ));
}

function logProductIn(Association $association): Product
{
    $weaver = Weaver::factory()->create(['association_id' => $association->id]);

    return Product::factory()->create(['weaver_id' => $weaver->id]);
}

it('records who added, changed and removed a photo, and when', function () {
    $user = User::factory()->association()->create();
    $product = logProductIn($user->association);
    Sanctum::actingAs($user);

    $this->postJson("/api/association/products/{$product->id}/photo", ['photo' => logJpeg('1.jpg')])->assertOk();
    $this->postJson("/api/association/products/{$product->id}/photo", ['photo' => logJpeg('2.jpg')])->assertOk();
    $this->deleteJson("/api/association/products/{$product->id}/photo")->assertOk();

    $events = ProductPhotoEvent::orderBy('id')->get();
    expect($events->pluck('action')->all())->toBe(['added', 'changed', 'removed']);
    expect($events->pluck('user_id')->unique()->all())->toBe([$user->id]);
    expect($events->every(fn ($e) => $e->product_id === $product->id && $e->created_at !== null))->toBeTrue();
});

it('records staff uploads with the staff user', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $product = logProductIn(Association::factory()->create(['municipality' => 'Bontoc']));
    Sanctum::actingAs($staff);

    $this->postJson("/api/staff/products/{$product->id}/photo", ['photo' => logJpeg('a.jpg')])->assertOk()
        ->assertJsonPath('product.latest_photo_event.action', 'added')
        ->assertJsonPath('product.latest_photo_event.user.name', $staff->name);

    expect(ProductPhotoEvent::first()->user_id)->toBe($staff->id);
});

it('does not let an administrator add or change a photo', function () {
    $admin = User::factory()->admin()->create();
    $product = logProductIn(Association::factory()->create());
    Sanctum::actingAs($admin);

    $this->postJson("/api/staff/products/{$product->id}/photo", ['photo' => logJpeg('a.jpg')])->assertForbidden();

    expect($product->fresh()->photo_path)->toBeNull();
    expect(ProductPhotoEvent::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('product-photos'))->toBe([]);
});

it('lets an administrator remove a photo and records it', function () {
    $admin = User::factory()->admin()->create();
    $product = logProductIn(Association::factory()->create());
    Storage::disk('local')->put('product-photos/22222222-2222-4222-8222-222222222222.jpg', 'x');
    $product->update(['photo_path' => '22222222-2222-4222-8222-222222222222.jpg']);
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/staff/products/{$product->id}/photo")->assertOk()
        ->assertJsonPath('product.photo_url', null)
        ->assertJsonPath('product.latest_photo_event.action', 'removed');

    Storage::disk('local')->assertMissing('product-photos/22222222-2222-4222-8222-222222222222.jpg');
    expect(ProductPhotoEvent::first()->user_id)->toBe($admin->id);
});

it('does not log a removal when the product had no photo', function () {
    $user = User::factory()->association()->create();
    $product = logProductIn($user->association);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/association/products/{$product->id}/photo")->assertOk();

    expect(ProductPhotoEvent::count())->toBe(0);
});

it('logs nothing when the change is refused', function () {
    $user = User::factory()->association()->create();
    $foreign = logProductIn(Association::factory()->create());
    $own = logProductIn($user->association);
    Sanctum::actingAs($user);

    $this->postJson("/api/association/products/{$foreign->id}/photo", ['photo' => logJpeg('a.jpg')])->assertForbidden();
    $this->postJson("/api/association/products/{$own->id}/photo", [
        'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
    ])->assertUnprocessable();

    expect(ProductPhotoEvent::count())->toBe(0);
});

it('never shows photo events or user names in the public scan result', function () {
    $staff = User::factory()->staff()->create(['name' => 'Secret Staffer Name']);
    $tag = NfcTag::factory()->create();
    ProductPhotoEvent::create(['product_id' => $tag->product_id, 'user_id' => $staff->id, 'action' => 'added']);

    $body = $this->postJson('/api/verify', ['uid' => $tag->uid])->assertOk()->getContent();

    expect($body)->not->toContain('Secret Staffer Name')->not->toContain('latest_photo_event');
});
