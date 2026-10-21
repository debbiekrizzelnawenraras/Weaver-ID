<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function newPasswordPayload(array $overrides = []): array
{
    return array_merge([
        'current_password' => 'password',
        'password' => 'Zr4$pWn81vKd',
        'password_confirmation' => 'Zr4$pWn81vKd',
    ], $overrides);
}

it('needs a login', function () {
    $this->postJson('/api/change-password', newPasswordPayload())->assertUnauthorized();
});

it('changes the password so only the new one logs in', function () {
    $user = User::factory()->staff()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/change-password', newPasswordPayload())
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false);

    expect(Hash::check('Zr4$pWn81vKd', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Zr4$pWn81vKd'])->assertOk();
});

it('refuses a wrong current password and leaves the password alone', function () {
    $user = User::factory()->staff()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/change-password', newPasswordPayload(['current_password' => 'not-it']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('lists every rule a weak new password breaks', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $errors = $this->postJson('/api/change-password', newPasswordPayload([
        'password' => 'abc', 'password_confirmation' => 'abc',
    ]))->assertUnprocessable()->json('errors.password');

    expect($errors)->toContain('Use at least 12 characters.')
        ->toContain('Add an uppercase letter.')
        ->toContain('Add a number.')
        ->toContain('Add a symbol, such as ! @ # ?');
});

it('refuses a password that contains the account holder\'s name', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['name' => 'Rose Wangdali', 'email' => 'rw@example.test']));

    $this->postJson('/api/change-password', newPasswordPayload([
        'password' => 'Wangdali#2026xx', 'password_confirmation' => 'Wangdali#2026xx',
    ]))->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Do not include your name or email in the password.');
});

it('refuses a confirmation that does not match', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->postJson('/api/change-password', newPasswordPayload(['password_confirmation' => 'Different#1234x']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('refuses the same password again', function () {
    $user = User::factory()->staff()->create(['password' => Hash::make('Tg7#kLm92xQv')]);
    Sanctum::actingAs($user);

    $this->postJson('/api/change-password', [
        'current_password' => 'Tg7#kLm92xQv',
        'password' => 'Tg7#kLm92xQv',
        'password_confirmation' => 'Tg7#kLm92xQv',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'The new password must be different from the current one.');
});

it('signs out the other devices and keeps this one', function () {
    $user = User::factory()->staff()->create();
    $login = ['email' => $user->email, 'password' => 'password'];

    $thisDevice = $this->postJson('/api/login', $login)->json('token');
    $otherDevice = $this->postJson('/api/login', $login)->json('token');
    $this->assertDatabaseCount('personal_access_tokens', 2);

    $this->withToken($thisDevice)->postJson('/api/change-password', newPasswordPayload())->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 1);

    $this->app['auth']->forgetGuards();
    $this->withToken($otherDevice)->getJson('/api/user')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($thisDevice)->getJson('/api/user')->assertOk();
});

it('limits guesses of the current password', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/change-password', newPasswordPayload(['current_password' => 'guess']))
            ->assertUnprocessable();
    }

    $this->postJson('/api/change-password', newPasswordPayload(['current_password' => 'guess']))
        ->assertStatus(429);
});
