<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function loginPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'email' => $user->email,
        'password' => 'password',
        'role' => $user->role,
    ], $overrides);
}

it('logs in with valid credentials and returns a token', function () {
    $user = User::factory()->staff()->create();

    $this->postJson('/api/login', loginPayload($user))
        ->assertOk()
        ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email', 'role', 'association_id']])
        ->assertJsonPath('user.role', 'staff');
});

it('rejects a wrong password', function () {
    $user = User::factory()->staff()->create();

    $this->postJson('/api/login', loginPayload($user, ['password' => 'wrong-password']))
        ->assertUnauthorized();
});

it('rejects a role that does not match the account', function () {
    $user = User::factory()->staff()->create();

    $this->postJson('/api/login', loginPayload($user, ['role' => 'admin']))
        ->assertForbidden();
});

it('logs in without a role and returns the account role', function () {
    $user = User::factory()->staff()->create();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.role', 'staff');
});

it('rejects an unknown role value', function () {
    $user = User::factory()->staff()->create();

    $this->postJson('/api/login', loginPayload($user, ['role' => 'superuser']))
        ->assertUnprocessable();
});

it('rejects an inactive account', function () {
    $user = User::factory()->staff()->inactive()->create();

    $this->postJson('/api/login', loginPayload($user))
        ->assertForbidden();
});

it('throttles repeated failed logins', function () {
    $user = User::factory()->staff()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', loginPayload($user, ['password' => 'wrong-password']))
            ->assertUnauthorized();
    }

    $this->postJson('/api/login', loginPayload($user, ['password' => 'wrong-password']))
        ->assertStatus(429);
});

it('deletes the token on logout', function () {
    $user = User::factory()->staff()->create();

    $token = $this->postJson('/api/login', loginPayload($user))->json('token');
    $this->assertDatabaseCount('personal_access_tokens', 1);

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects an expired token', function () {
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->staff()->create();

    $token = $this->postJson('/api/login', loginPayload($user))->json('token');

    $this->travel(61)->minutes();

    $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
});
