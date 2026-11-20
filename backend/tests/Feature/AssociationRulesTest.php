<?php

use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A complete staff filing for an association; override any field. */
function filing(array $over = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'association_name' => 'Filed Weavers '.$n,
        'contact_name' => 'Maria Dalog',
        'contact_phone' => '09171234567',
        'email' => "filed{$n}@example.test",
        'password' => 'Loom-Thread-2026!q',
        'privacy_consent' => true,
    ], $over);
}

it('refuses a second association with the same name, ignoring case, spaces and punctuation', function () {
    Association::factory()->create(['name' => "Rose Ann's Loom Art Gallery", 'municipality' => 'Bontoc']);
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    foreach (["Rose Ann's Loom Art Gallery", "rose anns  LOOM art gallery", "  ROSE ANN'S LOOM ART GALLERY  "] as $name) {
        $this->postJson('/api/staff/associations', filing(['association_name' => $name]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('association_name');
    }

    expect(Association::count())->toBe(1);
    expect(AssociationApplication::count())->toBe(0);
});

it('checks the name against every municipality and every user, not only the own scope', function () {
    Association::factory()->create(['name' => 'Besao Weavers', 'municipality' => 'Besao']);

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));
    $this->postJson('/api/staff/associations', filing(['association_name' => 'besao weavers']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('association_name');

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));
    $this->postJson('/api/staff/associations', filing(['association_name' => 'BESAO WEAVERS']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('association_name');

    expect(Association::count())->toBe(1);
});

it('refuses a name that already has an application waiting', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->postJson('/api/staff/associations', filing(['association_name' => 'Loom Makers']))->assertCreated();
    $this->postJson('/api/staff/associations', filing(['association_name' => 'loom makers']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('association_name');
});

it('files a staff-added association as a pending application, not as an association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc', 'name' => 'Staff Bontoc']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/associations', filing(['association_name' => '  Bontoc   Loom  Makers ', 'municipality' => 'Besao']))
        ->assertCreated()
        ->assertJsonPath('application.association_name', 'Bontoc Loom Makers')
        ->assertJsonPath('application.status', 'pending')
        // Always the staff member's own municipality, whatever was sent.
        ->assertJsonPath('application.municipality', 'Bontoc');

    expect(Association::count())->toBe(0);
    $application = AssociationApplication::firstOrFail();
    expect($application->submitted_by)->toBe($staff->id);

    // Staff can see it waiting, but cannot decide it.
    $this->getJson('/api/staff/association-applications')
        ->assertOk()
        ->assertJsonPath('applications.0.status', 'pending')
        ->assertJsonPath('applications.0.submitted_by', 'Staff Bontoc');
    $this->postJson("/api/applications/{$application->id}/approve")->assertForbidden();
});

it('lets the administrator approve a staff filing, and the association must change the temporary password', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc', 'name' => 'Staff Bontoc']));
    $this->postJson('/api/staff/associations', filing(['association_name' => 'Bontoc Loom Makers', 'email' => 'bontoc.makers@example.test']))->assertCreated();
    $application = AssociationApplication::firstOrFail();

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson('/api/applications?status=pending')
        ->assertOk()
        ->assertJsonPath('data.0.submitted_by', 'Staff Bontoc');
    $this->postJson("/api/applications/{$application->id}/approve")->assertOk();

    $account = User::where('email', 'bontoc.makers@example.test')->firstOrFail();
    expect($account->role)->toBe('association');
    expect($account->must_change_password)->toBeTrue();
    expect(Association::firstOrFail()->name)->toBe('Bontoc Loom Makers');
});

it('needs the contact details, a strong password and the consent for a staff filing', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->postJson('/api/staff/associations', ['association_name' => 'Loom Makers'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contact_name', 'contact_phone', 'email', 'password', 'privacy_consent']);
    $this->postJson('/api/staff/associations', filing(['password' => 'short']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('puts a product registered by staff under the chosen association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    Association::factory()->create(['name' => 'First Weavers', 'municipality' => 'Bontoc']);
    $second = Association::factory()->create(['name' => 'Second Weavers', 'municipality' => 'Bontoc']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/staff/products', [
        'association_id' => $second->id, 'title' => 'Blanket', 'category' => 'Traditional Woven Fabric',
    ])->assertCreated()
        ->assertJsonPath('product.association.name', 'Second Weavers')
        ->assertJsonPath('product.association.id', $second->id);

    expect($response->json('message'))->toContain('Second Weavers');

    $listed = collect($this->getJson('/api/staff/products')->assertOk()->json('products'))
        ->firstWhere('title', 'Blanket');
    expect($listed['association']['name'])->toBe('Second Weavers');
    expect(Product::first()->association_id)->toBe($second->id);
});

it('does not let staff register a product for an association in another municipality', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/products', ['association_id' => $foreign->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric'])
        ->assertForbidden();

    expect(Product::count())->toBe(0);
});

it('refuses a product for an inactive association', function () {
    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    $closed = Association::factory()->create(['municipality' => 'Bontoc', 'status' => 'inactive']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/staff/products', ['association_id' => $closed->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('association_id');
});

it('does not let the administrator register associations or products', function () {
    $association = Association::factory()->create(['municipality' => 'Bontoc']);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/staff/associations', filing())->assertForbidden();
    $this->getJson('/api/staff/association-applications')->assertForbidden();
    $this->postJson('/api/staff/products', ['association_id' => $association->id, 'title' => 'x', 'category' => 'Traditional Woven Fabric'])->assertForbidden();

    // The administrator can still look.
    $this->getJson('/api/staff/associations')->assertOk();
    $this->getJson('/api/staff/products')->assertOk();

    expect(Association::count())->toBe(1)->and(Product::count())->toBe(0);
});
