<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function staffPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Negosyo Staff', 'email' => 'ns@example.test',
        'password' => 'Tg7#kLm92xQv', 'role' => 'staff', 'municipality' => 'Sagada',
    ], $overrides);
}

it('flags an account an administrator creates and tells the dashboard at login', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/admin/users', staffPayload())
        ->assertCreated()
        ->assertJsonPath('user.must_change_password', true);

    $this->postJson('/api/login', ['email' => 'ns@example.test', 'password' => 'Tg7#kLm92xQv'])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true);
});

it('does not flag existing accounts', function () {
    $user = User::factory()->staff()->create();

    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false);
});

it('refuses a weak password when an administrator creates an account', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/admin/users', staffPayload(['password' => 'a-long-password-1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseMissing('users', ['email' => 'ns@example.test']);
});

it('refuses an administrator-chosen password that contains the new account\'s name', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/admin/users', staffPayload(['password' => 'Negosyo#2026xyz']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Do not include your name or email in the password.');

    $this->assertDatabaseMissing('users', ['email' => 'ns@example.test']);
});

it('lets a flagged account only read itself, change its password and log out', function () {
    $user = User::factory()->association()->mustChangePassword()->create();
    $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('token');

    $this->withToken($token)->getJson('/api/association/products')
        ->assertForbidden()
        ->assertJsonPath('code', 'password_change_required');
    $this->withToken($token)->getJson('/api/nfc-tags')
        ->assertForbidden()
        ->assertJsonPath('code', 'password_change_required');

    $this->withToken($token)->getJson('/api/user')->assertOk();
    $this->withToken($token)->postJson('/api/logout')->assertOk();
});

it('unlocks the account once the person has set their own password', function () {
    $user = User::factory()->association()->mustChangePassword()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/association/products')->assertForbidden();

    $this->postJson('/api/change-password', newPasswordPayload())->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->getJson('/api/association/products')->assertOk();
});

it('flags an account whose password an administrator resets', function () {
    $staff = User::factory()->staff()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->putJson("/api/admin/users/{$staff->id}", [
        'name' => $staff->name, 'email' => $staff->email, 'status' => 'active',
        'municipality' => 'Bontoc', 'password' => 'Zr4$pWn81vKd',
    ])->assertOk()->assertJsonPath('user.must_change_password', true);
});

it('does not flag an administrator who resets their own password', function () {
    Sanctum::actingAs($admin = User::factory()->admin()->create());

    $this->putJson("/api/admin/users/{$admin->id}", [
        'name' => $admin->name, 'email' => $admin->email, 'status' => 'active',
        'password' => 'Zr4$pWn81vKd',
    ])->assertOk()->assertJsonPath('user.must_change_password', false);
});

it('leaves the flag alone when an administrator edits an account without a new password', function () {
    $staff = User::factory()->staff()->mustChangePassword()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->putJson("/api/admin/users/{$staff->id}", [
        'name' => 'Renamed Staff', 'email' => $staff->email, 'status' => 'active', 'municipality' => 'Bontoc',
    ])->assertOk()->assertJsonPath('user.must_change_password', true);
});
