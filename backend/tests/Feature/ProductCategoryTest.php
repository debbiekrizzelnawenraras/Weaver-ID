<?php

use App\Models\Association;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('accepts only the two PIWPC categories when staff add a product', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $association = Association::factory()->create(['municipality' => 'Bontoc']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/products', ['association_id' => $association->id, 'title' => 'Tapis', 'category' => 'Indigenous Traditional Garment'])
        ->assertCreated()->assertJsonPath('product.category', Product::GARMENT);
    $this->postJson('/api/staff/products', ['association_id' => $association->id, 'title' => 'Runner', 'category' => 'Traditional Woven Fabric'])
        ->assertCreated();
    $this->postJson('/api/staff/products', ['association_id' => $association->id, 'title' => 'Bag', 'category' => 'Bags & Accessories'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');

    expect(Product::count())->toBe(2);
});

it('accepts only the two categories when an association adds or edits a product', function () {
    $user = User::factory()->association()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/association/products', ['title' => 'Wanes', 'category' => 'attire'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    $id = $this->postJson('/api/association/products', ['title' => 'Wanes', 'category' => Product::GARMENT])
        ->assertCreated()->json('product.id');

    $this->putJson("/api/association/products/{$id}", ['title' => 'Wanes', 'category' => 'Clothing', 'status' => 'active'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
});

it('sorts existing products into the two categories and restores them on rollback', function () {
    $migration = require database_path('migrations/2026_11_20_160000_two_product_categories.php');
    $migration->down();

    $association = Association::factory()->create();
    $rows = [
        ['Pinagpagan Pattern Blanket', 'Blanket / Textile', Product::FABRIC],
        ['Wallet', 'Personal Accessory', Product::FABRIC],
        ['Customized Tapis', 'Lower Body Clothing', Product::GARMENT],
        ['tapis', 'attire', Product::GARMENT],
        ['Wanes', 'Handwoven Indigenous Garment', Product::GARMENT],
        ['Backstrap-woven belt', 'Woven Product', Product::FABRIC],
        ['demo', 'demo', Product::FABRIC],
    ];
    foreach ($rows as [$title, $category]) {
        Product::create(['association_id' => $association->id, 'title' => $title, 'category' => $category, 'status' => 'active']);
    }

    $migration->up();
    foreach ($rows as [$title, , $expected]) {
        expect(Product::where('title', $title)->value('category'))->toBe($expected);
    }

    $migration->down();
    expect(Product::where('title', 'Wallet')->value('category'))->toBe('Personal Accessory');
    $migration->up();
});
