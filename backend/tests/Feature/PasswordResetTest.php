<?php

use App\Mail\EmailCodeMail;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

const RESET_NEW_PASSWORD = 'Zr4$pWn81vKd';

beforeEach(fn () => Mail::fake());

function resetMailTo(string $email): ?EmailCodeMail
{
    return Mail::sent(EmailCodeMail::class)->filter(fn (EmailCodeMail $m) => $m->hasTo($email))->last();
}

/** Ask for a reset code for a user and return the code that was emailed. */
function resetCodeFor(User $user): string
{
    test()->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
    $mail = resetMailTo($user->email);
    expect($mail)->not->toBeNull();

    return $mail->code;
}

function resetPayload(User $user, string $code, array $overrides = []): array
{
    return array_merge([
        'email' => $user->email,
        'code' => $code,
        'password' => RESET_NEW_PASSWORD,
        'password_confirmation' => RESET_NEW_PASSWORD,
    ], $overrides);
}

function wrongResetCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

// ---- asking for a code -------------------------------------------------------

it('emails a reset code to an active account of any role', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('resend_in', 60);

    $mail = resetMailTo($user->email);
    expect($mail)->not->toBeNull()
        ->and($mail->code)->toMatch('/^\d{6}$/')
        ->and($mail->purpose)->toBe('password_reset')
        ->and($mail->render())->toContain('Forgot password')->toContain($mail->code);
})->with(['admin', 'staff', 'association']);

it('gives the same answer for an unknown email and sends nothing', function () {
    $user = User::factory()->staff()->create();

    $known = $this->postJson('/api/password/forgot', ['email' => $user->email]);
    $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.test']);

    expect($unknown->status())->toBe($known->status())->toBe(200)
        ->and($unknown->json())->toBe($known->json());
    Mail::assertSent(EmailCodeMail::class, 1);
    expect(resetMailTo('nobody@example.test'))->toBeNull();
});

it('sends nothing to an inactive account, and says the same as for anyone else', function () {
    $user = User::factory()->inactive()->create();

    $this->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'If an account uses this email'));

    Mail::assertNothingSent();
    expect(EmailCode::count())->toBe(0);
});

it('finds the account whatever the capitals', function () {
    $user = User::factory()->staff()->create(['email' => 'staff.person@example.test']);

    $this->postJson('/api/password/forgot', ['email' => ' Staff.Person@Example.TEST '])->assertOk();

    expect(resetMailTo('staff.person@example.test'))->not->toBeNull();
});

it('sends one email when asked twice within a minute and answers both the same way', function () {
    $user = User::factory()->staff()->create();

    $first = $this->postJson('/api/password/forgot', ['email' => $user->email]);
    $second = $this->postJson('/api/password/forgot', ['email' => $user->email]);

    expect($second->status())->toBe(200)->and($second->json())->toBe($first->json());
    Mail::assertSent(EmailCodeMail::class, 1);

    $this->travel(61)->seconds();
    $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
    Mail::assertSent(EmailCodeMail::class, 2);
});

it('refuses an invalid address', function () {
    $this->postJson('/api/password/forgot', ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('is unavailable while email codes are off', function () {
    config(['weaver.email_codes.enabled' => false]);
    $user = User::factory()->staff()->create();

    $this->postJson('/api/password/forgot', ['email' => $user->email])
        ->assertStatus(503)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'switched off'));

    $this->postJson('/api/password/reset', resetPayload($user, '123456'))->assertStatus(503);
    Mail::assertNothingSent();
});

it('limits how many reset codes one address can be sent in an hour', function () {
    $user = User::factory()->staff()->create();

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
        $this->travel(61)->seconds();
    }

    $this->postJson('/api/password/forgot', ['email' => $user->email])->assertStatus(429);
    Mail::assertSent(EmailCodeMail::class, 6);
});

// ---- choosing the new password -----------------------------------------------

it('changes the password with the right code, so only the new one signs in', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code))
        ->assertOk()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Password changed'));

    expect(Hash::check(RESET_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
    $this->postJson('/api/login', ['email' => $user->email, 'password' => RESET_NEW_PASSWORD])->assertOk();
});

it('works for every role', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertOk();

    expect(Hash::check(RESET_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
})->with(['admin', 'staff', 'association']);

it('uses the code up', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertOk();
    expect(EmailCode::count())->toBe(0);

    $this->postJson('/api/password/reset', resetPayload($user, $code, [
        'password' => 'Qm8!vXc27rTn',
        'password_confirmation' => 'Qm8!vXc27rTn',
    ]))->assertUnprocessable()->assertJsonValidationErrors('code');

    expect(Hash::check(RESET_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('clears a forced first-login change, since the person chose this password', function () {
    $user = User::factory()->staff()->mustChangePassword()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('signs the account out everywhere', function () {
    $user = User::factory()->staff()->create();
    $user->createToken('weaver-id');
    $user->createToken('weaver-id');
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('refuses a wrong code and leaves the password alone', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, wrongResetCode($code)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue()
        ->and(EmailCode::firstOrFail()->attempts)->toBe(1);
});

it('says the same about a wrong code and an email without an account', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $wrong = $this->postJson('/api/password/reset', resetPayload($user, wrongResetCode($code)));
    $stranger = $this->postJson('/api/password/reset', [
        'email' => 'nobody@example.test',
        'code' => '123456',
        'password' => RESET_NEW_PASSWORD,
        'password_confirmation' => RESET_NEW_PASSWORD,
    ]);

    expect($stranger->status())->toBe($wrong->status())->toBe(422)
        ->and($stranger->json('errors.code'))->toBe($wrong->json('errors.code'));
});

it('kills the code after five wrong tries, even for the right code', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);
    $wrong = wrongResetCode($code);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/password/reset', resetPayload($user, $wrong))->assertUnprocessable();
    }

    $this->postJson('/api/password/reset', resetPayload($user, $code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses a code after ten minutes', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->travel(11)->minutes();

    $this->postJson('/api/password/reset', resetPayload($user, $code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('does not accept the code of an application for a reset', function () {
    $user = User::factory()->staff()->create();
    $this->postJson('/api/email-codes', ['email' => $user->email])->assertOk();
    $applicationCode = resetMailTo($user->email)->code;

    $this->postJson('/api/password/reset', resetPayload($user, $applicationCode))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('applies the password rules and keeps the tries on the code', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, wrongResetCode($code), [
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(EmailCode::firstOrFail()->attempts)->toBe(0);

    // The same code still works with a good password.
    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertOk();
});

it('refuses a password that contains the name or the email of the account', function () {
    $user = User::factory()->staff()->create(['name' => 'Juanita Bolinget', 'email' => 'juanita.bolinget@example.test']);
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code, [
        'password' => 'Juanita#Bolinget7',
        'password_confirmation' => 'Juanita#Bolinget7',
    ]))->assertUnprocessable()->assertJsonValidationErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('wants the confirmation to match', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, $code, ['password_confirmation' => 'Something-Else-9']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('wants a six-digit code', function (mixed $typed) {
    $user = User::factory()->staff()->create();
    resetCodeFor($user);

    $this->postJson('/api/password/reset', resetPayload($user, (string) $typed))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(EmailCode::firstOrFail()->attempts)->toBe(0);
})->with(['', '12345', '1234567', 'abcdef']);

it('does not reset an inactive account even with a code', function () {
    $user = User::factory()->staff()->create();
    $code = resetCodeFor($user);
    $user->update(['status' => 'inactive']);

    $this->postJson('/api/password/reset', resetPayload($user, $code))->assertUnprocessable();

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('limits how many times the code can be tried from one address in an hour', function () {
    $user = User::factory()->staff()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/password/reset', resetPayload($user, '123456'))->assertUnprocessable();
    }

    $this->postJson('/api/password/reset', resetPayload($user, '123456'))->assertStatus(429);
});
