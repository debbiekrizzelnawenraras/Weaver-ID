<?php

use App\Mail\EmailCodeMail;
use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

uses(RefreshDatabase::class);

// Email codes are on here (the default). The form's other rules are tested in AssociationApplicationTest
// with the codes switched off.

const CODE_PASSWORD = 'Tg7#kLm92xQv';
const CODE_EMAIL = 'maria.dulnuan@example.test';

beforeEach(fn () => Mail::fake());

function codeForm(array $overrides = []): array
{
    return array_merge([
        'association_name' => 'Sagada Inabel Weavers Association',
        'municipality' => 'Sagada',
        'contact_name' => 'Maria Dulnuan',
        'contact_phone' => '0917 123 4567',
        'email' => CODE_EMAIL,
        'password' => CODE_PASSWORD,
        'password_confirmation' => CODE_PASSWORD,
        'registration_details' => null,
        'privacy_consent' => true,
    ], $overrides);
}

/** The code in the newest email sent to an address. */
function emailedCode(string $email): string
{
    $mail = Mail::sent(EmailCodeMail::class)->filter(fn (EmailCodeMail $m) => $m->hasTo($email))->last();
    expect($mail)->not->toBeNull("no email was sent to {$email}");

    return $mail->code;
}

/** Ask for a code the way the page does and return what the email said. */
function askForCode(string $email = CODE_EMAIL): string
{
    test()->postJson('/api/email-codes', ['email' => $email])->assertOk();

    return emailedCode($email);
}

/** Drop the fake from beforeEach so the next send goes through the real mail manager. */
function realMail(): void
{
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    Mail::clearResolvedInstances();
}

/** Make every send fail the way a missing internet connection does, through the real mail manager. */
function breakMail(): void
{
    realMail();
    // The reason for a failed send goes to the log. Catch it here so the test run does not write fake errors
    // into the real storage/logs/laravel.log, where they would look like a real mail problem.
    Log::spy();
    Mail::extend('broken', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused');
        }

        public function __toString(): string
        {
            return 'broken://';
        }
    });
    config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
}

function fixMail(): void
{
    realMail();
    config(['mail.default' => 'array']);
}

function wrongCodeFor(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

// ---- the page learns whether codes are on ------------------------------------

it('tells the form that email codes are on', function () {
    $this->getJson('/api/municipalities')->assertOk()->assertJsonPath('email_codes', true);
});

it('tells the form that email codes are off', function () {
    config(['weaver.email_codes.enabled' => false]);

    $this->getJson('/api/municipalities')->assertOk()->assertJsonPath('email_codes', false);
});

// ---- asking for a code -------------------------------------------------------

it('emails a 6-digit code to the address, without a login', function () {
    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])
        ->assertOk()
        ->assertJsonPath('resend_in', 60)
        ->assertJsonPath('expires_in', 600);

    Mail::assertSent(EmailCodeMail::class, 1);
    $code = emailedCode(CODE_EMAIL);
    expect($code)->toMatch('/^\d{6}$/');

    $text = Mail::sent(EmailCodeMail::class)->first()->render();
    expect($text)->toContain($code)->toContain('10 minutes')->toContain('ignore this email');
});

it('keeps only a scrambled copy of the code, valid for ten minutes', function () {
    $code = askForCode();

    $row = EmailCode::firstOrFail();
    expect($row->email)->toBe(CODE_EMAIL)
        ->and($row->purpose)->toBe('application')
        ->and($row->code_hash)->not->toContain($code)->toHaveLength(64)
        ->and($row->attempts)->toBe(0)
        ->and((int) now()->diffInMinutes($row->expires_at, false))->toBeBetween(9, 10);
});

it('treats the address without regard to capitals and spaces', function () {
    $this->postJson('/api/email-codes', ['email' => '  Maria.Dulnuan@Example.Test '])->assertOk();

    expect(EmailCode::firstOrFail()->email)->toBe(CODE_EMAIL);
});

it('refuses a missing or invalid address', function (mixed $email) {
    $this->postJson('/api/email-codes', ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Mail::assertNothingSent();
})->with([[null], [''], ['not-an-email']]);

it('makes the person wait a minute before another code, then replaces the first', function () {
    $first = askForCode();

    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])
        ->assertStatus(429)
        ->assertJsonPath('retry_after', 60);
    Mail::assertSent(EmailCodeMail::class, 1);

    $this->travel(61)->seconds();
    $second = askForCode();
    Mail::assertSent(EmailCodeMail::class, 2);
    expect(EmailCode::count())->toBe(1);

    // Only the newest code works.
    if ($first !== $second) {
        $this->postJson('/api/association-applications', codeForm(['email_code' => $first]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_code');
    }
    $this->postJson('/api/association-applications', codeForm(['email_code' => $second]))->assertCreated();
});

it('says so when the email cannot be sent and leaves no code behind', function () {
    breakMail();

    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])
        ->assertStatus(503)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'could not be sent'));

    expect(EmailCode::count())->toBe(0);

    // The log gets the reason, never a code.
    Log::shouldHaveReceived('error')->once()
        ->withArgs(fn (string $m) => str_contains($m, 'Connection refused') && ! preg_match('/\d{6}/', $m));
});

it('keeps the earlier code working when a second email cannot be sent', function () {
    $first = askForCode();
    $this->travel(61)->seconds();

    breakMail();
    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])->assertStatus(503);

    fixMail();
    $this->postJson('/api/association-applications', codeForm(['email_code' => $first]))->assertCreated();
});

it('sends nothing while email codes are off', function () {
    config(['weaver.email_codes.enabled' => false]);

    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])->assertStatus(503);

    Mail::assertNothingSent();
    expect(EmailCode::count())->toBe(0);
});

it('limits how many codes one address can be sent in an hour', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])->assertOk();
        $this->travel(61)->seconds();
    }

    $this->postJson('/api/email-codes', ['email' => CODE_EMAIL])->assertStatus(429);
    Mail::assertSent(EmailCodeMail::class, 6);
});

it('limits how many codes one place can ask for in an hour', function () {
    for ($i = 1; $i <= 20; $i++) {
        $this->postJson('/api/email-codes', ['email' => "person{$i}@example.test"])->assertOk();
    }

    $this->postJson('/api/email-codes', ['email' => 'person21@example.test'])->assertStatus(429);
});

// ---- applying with the code --------------------------------------------------

it('saves the application as email verified when the right code is typed, and uses the code up', function () {
    $code = askForCode();

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    $application = AssociationApplication::firstOrFail();
    expect($application->email_verified_at)->not->toBeNull();
    expect(EmailCode::count())->toBe(0);
});

it('needs the code', function () {
    $this->postJson('/api/association-applications', codeForm())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_code');

    expect(AssociationApplication::count())->toBe(0);
});

it('wants exactly six digits', function (string $typed) {
    askForCode();

    $this->postJson('/api/association-applications', codeForm(['email_code' => $typed]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_code');

    expect(AssociationApplication::count())->toBe(0)
        ->and(EmailCode::firstOrFail()->attempts)->toBe(0);
})->with(['12345', '1234567', 'abcdef', '12 345']);

it('refuses a code that was never asked for', function () {
    $this->postJson('/api/association-applications', codeForm(['email_code' => '123456']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email_code.0', fn (string $m) => str_contains($m, 'Send code'));

    expect(AssociationApplication::count())->toBe(0);
});

it('refuses a wrong code and counts the try', function () {
    $code = askForCode();

    $this->postJson('/api/association-applications', codeForm(['email_code' => wrongCodeFor($code)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email_code.0', fn (string $m) => str_contains($m, 'not correct'));

    expect(AssociationApplication::count())->toBe(0)
        ->and(EmailCode::firstOrFail()->attempts)->toBe(1);
});

it('kills the code after five wrong tries, even for the right code', function () {
    $code = askForCode();
    $wrong = wrongCodeFor($code);

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/association-applications', codeForm(['email_code' => $wrong]))->assertUnprocessable();
    }

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email_code.0', fn (string $m) => str_contains($m, 'Too many wrong tries'));

    expect(AssociationApplication::count())->toBe(0);

    // A new code starts again.
    $this->travel(61)->seconds();
    $fresh = askForCode();
    $this->postJson('/api/association-applications', codeForm(['email_code' => $fresh]))->assertCreated();
});

it('refuses a code after ten minutes', function () {
    $code = askForCode();

    $this->travel(11)->minutes();

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email_code.0', fn (string $m) => str_contains($m, 'expired'));

    expect(AssociationApplication::count())->toBe(0);
});

it('accepts the code up to the last minute', function () {
    $code = askForCode();

    $this->travel(9)->minutes();

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))->assertCreated();
});

it('does not accept a code for a different address', function () {
    $code = askForCode('someone.else@example.test');

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_code');

    expect(AssociationApplication::count())->toBe(0);
});

it('does not let a code from forgot-password confirm an application', function () {
    $user = User::factory()->staff()->create(['email' => CODE_EMAIL]);
    $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
    $resetCode = emailedCode(CODE_EMAIL);

    $this->postJson('/api/association-applications', codeForm(['email_code' => $resetCode]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_code');
});

it('cannot use one code for two applications', function () {
    $code = askForCode();
    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))->assertCreated();

    $this->postJson('/api/association-applications', codeForm([
        'association_name' => 'A Different Weavers Group',
        'email_code' => $code,
    ]))->assertUnprocessable()->assertJsonValidationErrors('email_code');

    expect(AssociationApplication::count())->toBe(1);
});

it('does not use up tries on a code while the rest of the form is wrong', function () {
    $code = askForCode();

    $this->postJson('/api/association-applications', codeForm(['contact_phone' => '123', 'email_code' => wrongCodeFor($code)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('contact_phone')
        ->assertJsonMissingValidationErrors('email_code');

    expect(EmailCode::firstOrFail()->attempts)->toBe(0);
});

it('keeps the code usable when the association name turns out to be taken', function () {
    Association::factory()->create(['name' => 'Sagada Inabel Weavers Association']);
    $code = askForCode();

    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('association_name');
    expect(EmailCode::count())->toBe(1);

    $this->postJson('/api/association-applications', codeForm(['association_name' => 'Sagada Weavers Cooperative', 'email_code' => $code]))
        ->assertCreated();
});

it('says an email is in use only to someone who typed the right code', function () {
    User::factory()->staff()->create(['email' => CODE_EMAIL]);

    // The code is sent as for any address, so asking for one reveals nothing.
    $code = askForCode();

    // A wrong code gets the code error, not the "cannot be used" answer.
    $this->postJson('/api/association-applications', codeForm(['email_code' => wrongCodeFor($code)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email_code')
        ->assertJsonMissingValidationErrors('email');

    // The right code reaches the email check.
    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingValidationErrors('email_code');

    expect(AssociationApplication::count())->toBe(0);
});

// ---- email codes off ---------------------------------------------------------

it('saves the application as email not verified while codes are off', function () {
    config(['weaver.email_codes.enabled' => false]);

    $this->postJson('/api/association-applications', codeForm())->assertCreated();

    expect(AssociationApplication::firstOrFail()->email_verified_at)->toBeNull();
});

it('ignores a code sent while codes are off', function () {
    config(['weaver.email_codes.enabled' => false]);

    $this->postJson('/api/association-applications', codeForm(['email_code' => '123456']))->assertCreated();

    expect(AssociationApplication::firstOrFail()->email_verified_at)->toBeNull();
});

// ---- what staff see ----------------------------------------------------------

it('shows staff whether the email was confirmed', function () {
    $code = askForCode();
    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))->assertCreated();

    config(['weaver.email_codes.enabled' => false]);
    $this->postJson('/api/association-applications', codeForm([
        'association_name' => 'Besao Weavers Circle',
        'email' => 'besao@example.test',
    ]))->assertCreated();

    Sanctum::actingAs(User::factory()->admin()->create());
    $rows = collect($this->getJson('/api/applications')->assertOk()->json('data'))->keyBy('email');

    expect($rows[CODE_EMAIL]['email_verified'])->toBeTrue()
        ->and($rows['besao@example.test']['email_verified'])->toBeFalse();
});

it('marks the new account email as confirmed only when the applicant confirmed it', function () {
    $code = askForCode();
    $this->postJson('/api/association-applications', codeForm(['email_code' => $code]))->assertCreated();
    $confirmed = AssociationApplication::firstOrFail();

    config(['weaver.email_codes.enabled' => false]);
    $this->postJson('/api/association-applications', codeForm([
        'association_name' => 'Besao Weavers Circle',
        'email' => 'besao@example.test',
    ]))->assertCreated();
    $unconfirmed = AssociationApplication::where('email', 'besao@example.test')->firstOrFail();

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->postJson("/api/applications/{$confirmed->id}/approve")->assertOk();
    $this->postJson("/api/applications/{$unconfirmed->id}/approve")->assertOk();

    expect(User::where('email', CODE_EMAIL)->firstOrFail()->email_verified_at)->not->toBeNull()
        ->and(User::where('email', 'besao@example.test')->firstOrFail()->email_verified_at)->toBeNull();
});
