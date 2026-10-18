<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

// Tiny real image files. UploadedFile::fake()->image() needs the GD extension,
// which some PHP builds (for example Herd-lite) do not ship, so the tests build
// their images from raw bytes instead.
function photoPng(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

function photoJpeg(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
    ));
}

function photoProductIn(Association $association): Product
{
    $weaver = Weaver::factory()->create(['association_id' => $association->id]);

    return Product::factory()->create(['weaver_id' => $weaver->id]);
}

it('lets an association user upload a photo for its own product', function () {
    $user = User::factory()->association()->create();
    $product = photoProductIn($user->association);
    Sanctum::actingAs($user);

    $response = $this->postJson("/api/association/products/{$product->id}/photo", [
        'photo' => photoJpeg('blanket.jpg'),
    ])->assertOk();

    $product->refresh();
    expect($product->photo_path)->toEndWith('.jpg');
    Storage::disk('local')->assertExists('product-photos/'.$product->photo_path);
    expect($response->json('product.photo_url'))->toBe('/api/product-photos/'.$product->photo_path);
    expect($response->json('product'))->not->toHaveKey('photo_path');
});

it('stops an association user changing the photo of another association', function () {
    $user = User::factory()->association()->create();
    $foreign = photoProductIn(Association::factory()->create());
    Sanctum::actingAs($user);

    $this->postJson("/api/association/products/{$foreign->id}/photo", [
        'photo' => photoJpeg('x.jpg'),
    ])->assertForbidden();

    $this->deleteJson("/api/association/products/{$foreign->id}/photo")->assertForbidden();
    expect($foreign->fresh()->photo_path)->toBeNull();
});

it('lets staff upload a photo inside their municipality', function () {
    $staff = User::factory()->staff()->create();
    $association = Association::factory()->create(['municipality' => $staff->municipality]);
    $product = photoProductIn($association);
    Sanctum::actingAs($staff);

    $this->postJson("/api/staff/products/{$product->id}/photo", [
        'photo' => photoPng('a.png'),
    ])->assertOk();

    expect($product->fresh()->photo_path)->toEndWith('.png');
});

it('stops staff changing the photo of a product in another municipality', function () {
    $staff = User::factory()->staff()->create();
    $association = Association::factory()->create(['municipality' => 'Somewhere Else']);
    $product = photoProductIn($association);
    Sanctum::actingAs($staff);

    $this->postJson("/api/staff/products/{$product->id}/photo", [
        'photo' => photoJpeg('a.jpg'),
    ])->assertForbidden();
});

it('rejects files that are not images or are too large', function () {
    $user = User::factory()->association()->create();
    $product = photoProductIn($user->association);
    Sanctum::actingAs($user);

    $this->postJson("/api/association/products/{$product->id}/photo", [
        'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable();

    $this->postJson("/api/association/products/{$product->id}/photo", [
        'photo' => UploadedFile::fake()->create('huge.jpg', 4000, 'image/jpeg'),
    ])->assertUnprocessable();

    $this->postJson("/api/association/products/{$product->id}/photo", [])->assertUnprocessable();
    expect($product->fresh()->photo_path)->toBeNull();
});

it('replaces the old file when a new photo is uploaded and deletes it on removal', function () {
    $user = User::factory()->association()->create();
    $product = photoProductIn($user->association);
    Sanctum::actingAs($user);

    $this->postJson("/api/association/products/{$product->id}/photo", ['photo' => photoJpeg('1.jpg')]);
    $first = $product->fresh()->photo_path;

    $this->postJson("/api/association/products/{$product->id}/photo", ['photo' => photoJpeg('2.jpg')]);
    $second = $product->fresh()->photo_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing('product-photos/'.$first);
    Storage::disk('local')->assertExists('product-photos/'.$second);

    $this->deleteJson("/api/association/products/{$product->id}/photo")->assertOk()
        ->assertJsonPath('product.photo_url', null);
    Storage::disk('local')->assertMissing('product-photos/'.$second);
});

it('requires a login to change photos', function () {
    $product = Product::factory()->create();

    $this->postJson("/api/staff/products/{$product->id}/photo", [
        'photo' => photoJpeg('a.jpg'),
    ])->assertUnauthorized();
});

it('includes the photo address in an authenticated scan and serves the file', function () {
    $tag = NfcTag::factory()->create();
    $product = $tag->product;
    Storage::disk('local')->put('product-photos/11111111-1111-4111-8111-111111111111.jpg', 'jpgdata');
    $product->update(['photo_path' => '11111111-1111-4111-8111-111111111111.jpg']);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated')
        ->assertJsonPath('product.photo_url', '/api/product-photos/11111111-1111-4111-8111-111111111111.jpg');

    $this->get('/api/product-photos/11111111-1111-4111-8111-111111111111.jpg')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('returns a null photo address when the product has no photo', function () {
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('product.photo_url', null);
});

it('never shows a photo for tags that are not authenticated', function () {
    $tag = NfcTag::factory()->create(['status' => 'revoked']);
    $tag->product->update(['photo_path' => 'x.jpg']);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonMissingPath('product');
});

it('answers 404 for unknown or malformed photo names', function () {
    $this->get('/api/product-photos/22222222-2222-4222-8222-222222222222.jpg')->assertNotFound();
    $this->get('/api/product-photos/..%2F.env')->assertNotFound();
    $this->get('/api/product-photos/notes.txt')->assertNotFound();
});
