<?php

use App\Models\Association;
use App\Models\Product;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('refuses a second association with the same name, ignoring case, spaces and punctuation', function () {
    Association::factory()->create(['name' => "Rose Ann's Loom Art Gallery", 'municipality' => 'Bontoc']);
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    foreach (["Rose Ann's Loom Art Gallery", "rose anns  LOOM art gallery", "  ROSE ANN'S LOOM ART GALLERY  "] as $name) {
        $this->postJson('/api/staff/associations', ['name' => $name, 'municipality' => 'Bontoc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    expect(Association::count())->toBe(1);
});

it('checks the name against every municipality and every user, not only the own scope', function () {
    Association::factory()->create(['name' => 'Besao Weavers', 'municipality' => 'Besao']);

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));
    $this->postJson('/api/staff/associations', ['name' => 'besao weavers', 'municipality' => 'Bontoc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->postJson('/api/staff/associations', ['name' => 'BESAO WEAVERS', 'municipality' => 'Sagada'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect(Association::count())->toBe(1);
});

it('still accepts a different name and stores it with single spaces', function () {
    Association::factory()->create(['name' => 'Besao Weavers', 'municipality' => 'Besao']);
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->postJson('/api/staff/associations', ['name' => '  Bontoc   Loom  Makers ', 'municipality' => 'Bontoc'])
        ->assertCreated()
        ->assertJsonPath('association.name', 'Bontoc Loom Makers');
});

it('puts a product registered by staff under the association of the chosen member', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $first = Association::factory()->create(['name' => 'First Weavers', 'municipality' => 'Bontoc']);
    $second = Association::factory()->create(['name' => 'Second Weavers', 'municipality' => 'Bontoc']);
    $member = Weaver::factory()->create(['association_id' => $second->id, 'name' => 'Member Two']);
    Weaver::factory()->create(['association_id' => $first->id, 'name' => 'Member One']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/staff/products', [
        'weaver_id' => $member->id, 'title' => 'Blanket', 'category' => 'Textile',
    ])->assertCreated()
        ->assertJsonPath('product.weaver.association.name', 'Second Weavers')
        ->assertJsonPath('product.weaver.association.id', $second->id);

    expect($response->json('message'))->toContain('Second Weavers')->toContain('Member Two');

    $listed = collect($this->getJson('/api/staff/products')->assertOk()->json('products'))
        ->firstWhere('title', 'Blanket');
    expect($listed['weaver']['association']['name'])->toBe('Second Weavers');
    expect(Product::first()->weaver->association_id)->toBe($second->id);
});

it('does not let staff register a product for a member of an association in another municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    $member = Weaver::factory()->create(['association_id' => $foreign->id]);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/products', ['weaver_id' => $member->id, 'title' => 'x', 'category' => 'y'])
        ->assertForbidden();

    expect(Product::count())->toBe(0);
});
