<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('keeps the admin endpoints away from staff and association users', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson('/api/admin/summary')->assertForbidden();
    $this->getJson('/api/admin/scan-logs')->assertForbidden();
    $this->getJson('/api/admin/users')->assertForbidden();
    $this->postJson('/api/admin/users', [])->assertForbidden();
})->with(['staff', 'association']);

it('reports registry, tag and scan numbers', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    $tag = NfcTag::factory()->create(['issued_by' => $staff->id]);
    NfcTag::factory()->revoked()->create(['issued_by' => $admin->id]);
    Product::factory()->inactive()->create();

    ScanLog::create(['uid' => $tag->uid, 'nfc_tag_id' => $tag->id, 'result' => 'authenticated']);
    ScanLog::create(['uid' => $tag->uid, 'nfc_tag_id' => $tag->id, 'result' => 'authenticated']);
    ScanLog::create(['uid' => 'AABBCCDD', 'result' => 'unregistered']);

    Sanctum::actingAs($admin);

    $this->getJson('/api/admin/summary')
        ->assertOk()
        ->assertJsonPath('tags.active', 1)
        ->assertJsonPath('tags.revoked', 1)
        ->assertJsonPath('tags.issued_by_role.staff', 1)
        ->assertJsonPath('tags.issued_by_role.admin', 1)
        ->assertJsonPath('tags.issued_by_role.association', 0)
        ->assertJsonPath('products.with_active_tag', 1)
        ->assertJsonPath('scans.total', 3)
        ->assertJsonPath('scans.authenticated', 2)
        ->assertJsonPath('scans.unregistered', 1)
        ->assertJsonPath('scans.suspicious', 0);
});

it('lists scan logs and filters them by result', function () {
    ScanLog::create(['uid' => 'AABBCCDD', 'result' => 'unregistered']);
    ScanLog::create(['uid' => 'AABBCCDD', 'result' => 'suspicious']);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/admin/scan-logs')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/admin/scan-logs?result=suspicious')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/admin/scan-logs?result=bogus')->assertStatus(422);
});

it('creates a staff user and requires a municipality', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $payload = [
        'name' => 'Negosyo Staff', 'email' => 'ns@example.test',
        'password' => 'a-long-password-1', 'role' => 'staff',
    ];

    $this->postJson('/api/admin/users', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('municipality');

    $this->postJson('/api/admin/users', $payload + ['municipality' => 'Sagada'])
        ->assertCreated()
        ->assertJsonPath('user.municipality', 'Sagada')
        ->assertJsonMissingPath('user.password');
});

it('creates an association user and requires an association', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $association = Association::factory()->create();

    $payload = [
        'name' => 'Assoc User', 'email' => 'au@example.test',
        'password' => 'a-long-password-1', 'role' => 'association',
    ];

    $this->postJson('/api/admin/users', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('association_id');

    $this->postJson('/api/admin/users', $payload + ['association_id' => $association->id])
        ->assertCreated()
        ->assertJsonPath('user.association_id', $association->id);
});

it('rejects weak passwords, duplicate emails and unknown roles', function () {
    Sanctum::actingAs($admin = User::factory()->admin()->create());

    $this->postJson('/api/admin/users', [
        'name' => 'x', 'email' => $admin->email, 'password' => 'short', 'role' => 'public',
    ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'role']);
});

it('deactivates a user, signs them out and blocks them', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $staff->createToken('t');
    Sanctum::actingAs($admin);

    $this->putJson("/api/admin/users/{$staff->id}", [
        'name' => $staff->name, 'email' => $staff->email,
        'status' => 'inactive', 'municipality' => 'Bontoc',
    ])->assertOk()->assertJsonPath('user.status', 'inactive');

    expect($staff->tokens()->count())->toBe(0);
});

it('does not let an admin deactivate their own account', function () {
    Sanctum::actingAs($admin = User::factory()->admin()->create());

    $this->putJson("/api/admin/users/{$admin->id}", [
        'name' => $admin->name, 'email' => $admin->email, 'status' => 'inactive',
    ])->assertStatus(422);

    expect($admin->fresh()->status)->toBe('active');
});

it('resets a password and signs the user out', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $staff->createToken('t');
    Sanctum::actingAs($admin);

    $this->putJson("/api/admin/users/{$staff->id}", [
        'name' => $staff->name, 'email' => $staff->email, 'status' => 'active',
        'municipality' => 'Bontoc', 'password' => 'brand-new-password-9',
    ])->assertOk();

    expect($staff->tokens()->count())->toBe(0);
    $this->postJson('/api/login', [
        'email' => $staff->email, 'password' => 'brand-new-password-9', 'role' => 'staff',
    ])->assertOk();
});
