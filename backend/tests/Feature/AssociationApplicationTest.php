<?php

use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const APPL_PASSWORD = 'Tg7#kLm92xQv';

function applPayload(array $overrides = []): array
{
    return array_merge([
        'association_name' => 'Sagada Inabel Weavers Association',
        'municipality' => 'Sagada',
        'contact_name' => 'Maria Dulnuan',
        'contact_phone' => '0917 123 4567',
        'email' => 'maria.dulnuan@example.test',
        'password' => APPL_PASSWORD,
        'password_confirmation' => APPL_PASSWORD,
        'registration_details' => 'DTI-Sagada accreditation 2024-118',
        'privacy_consent' => true,
    ], $overrides);
}

/** A pending application written straight to the table, as the public form would have saved it. */
function applCreate(array $overrides = []): AssociationApplication
{
    static $n = 0;
    $n++;

    return AssociationApplication::create(array_merge([
        'reference' => 'AP-20261022-T'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'association_name' => 'Weavers Group '.$n,
        'municipality' => 'Sagada',
        'contact_name' => 'Contact Person '.$n,
        'contact_phone' => '09171234567',
        'email' => 'contact'.$n.'@example.test',
        'password_hash' => Hash::make(APPL_PASSWORD),
        'registration_details' => null,
        'status' => 'pending',
        'consented_at' => now(),
        'notice_version' => '2026-10',
    ], $overrides));
}

function applStaff(string $municipality = 'Sagada'): User
{
    return User::factory()->staff()->create(['municipality' => $municipality]);
}

// ---- public: the form's choices ---------------------------------------------

it('lists the municipalities without a login', function () {
    $this->getJson('/api/municipalities')
        ->assertOk()
        ->assertJsonCount(10, 'municipalities')
        ->assertJsonFragment(['municipalities' => config('weaver.municipalities')]);

    expect(config('weaver.municipalities'))->toContain('Bontoc', 'Sagada', 'Besao', 'Paracelis');
});

// ---- public: applying -------------------------------------------------------

it('saves an application as pending and creates no association or account', function () {
    $users = User::count();

    $response = $this->postJson('/api/association-applications', applPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    expect($response->json('reference'))->toMatch('/^AP-\d{8}-[A-Z0-9]{5}$/');

    $application = AssociationApplication::firstOrFail();
    expect($application->reference)->toBe($response->json('reference'))
        ->and($application->status)->toBe('pending')
        ->and($application->association_name)->toBe('Sagada Inabel Weavers Association')
        ->and($application->municipality)->toBe('Sagada')
        ->and($application->notice_version)->toBe(config('weaver.application_notice_version'))
        ->and($application->consented_at)->not->toBeNull()
        ->and(Association::count())->toBe(0)
        ->and(User::count())->toBe($users);
});

it('keeps the chosen password only as a hash and never returns it', function () {
    $response = $this->postJson('/api/association-applications', applPayload())->assertCreated();

    $application = AssociationApplication::firstOrFail();
    expect($application->password_hash)->not->toBe(APPL_PASSWORD)
        ->and(Hash::check(APPL_PASSWORD, $application->password_hash))->toBeTrue()
        ->and($response->getContent())->not->toContain(APPL_PASSWORD)
        ->and($response->getContent())->not->toContain('password_hash')
        ->and($application->toArray())->not->toHaveKey('password_hash');
});

it('tidies names and brings a mobile number to the local form', function () {
    $this->postJson('/api/association-applications', applPayload([
        'association_name' => '  Sagada   Inabel  Weavers   Association ',
        'contact_phone' => '+63 917-123-4567',
        'registration_details' => '   ',
    ]))->assertCreated();

    $application = AssociationApplication::firstOrFail();
    expect($application->association_name)->toBe('Sagada Inabel Weavers Association')
        ->and($application->contact_phone)->toBe('09171234567')
        ->and($application->registration_details)->toBeNull();
});

it('validates the fields', function (array $change, string $field) {
    $this->postJson('/api/association-applications', applPayload($change))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(AssociationApplication::count())->toBe(0);
})->with([
    'no name' => [['association_name' => ''], 'association_name'],
    'name too short' => [['association_name' => 'AB'], 'association_name'],
    'municipality not in the list' => [['municipality' => 'Baguio'], 'municipality'],
    'no municipality' => [['municipality' => ''], 'municipality'],
    'no contact person' => [['contact_name' => ''], 'contact_name'],
    'landline number' => [['contact_phone' => '074 602 1234'], 'contact_phone'],
    'number too short' => [['contact_phone' => '091712345'], 'contact_phone'],
    'bad email' => [['email' => 'maria.at.example'], 'email'],
    'confirmation differs' => [['password_confirmation' => 'Different#Pass99'], 'password'],
    'privacy notice not accepted' => [['privacy_consent' => false], 'privacy_consent'],
    'details too long' => [['registration_details' => str_repeat('x', 151)], 'registration_details'],
]);

it('refuses a password that breaks the rules and says which', function () {
    $response = $this->postJson('/api/association-applications', applPayload([
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]))->assertUnprocessable()->assertJsonValidationErrors('password');

    expect($response->json('errors.password'))->toContain('Use at least 12 characters.');
});

it('refuses a password that contains the contact name or the email', function (string $password) {
    $this->postJson('/api/association-applications', applPayload([
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertUnprocessable()->assertJsonValidationErrors('password');
})->with([
    'the name' => 'Dulnuan#Loom2026x',
    'the email before the @' => 'Xq9#maria.dulnuan!',
]);

it('refuses a name that is already a registered association, ignoring case, spaces and punctuation', function () {
    Association::factory()->create(['name' => "Rose Ann's Loom", 'municipality' => 'Bontoc']);

    $this->postJson('/api/association-applications', applPayload(['association_name' => 'rose anns   loom']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('association_name');

    expect(AssociationApplication::count())->toBe(0);
});

it('refuses a second pending application for the same association', function () {
    applCreate(['association_name' => 'Sagada Inabel Weavers Association']);

    $this->postJson('/api/association-applications', applPayload(['association_name' => 'SAGADA INABEL WEAVERS ASSOCIATION', 'email' => 'other@example.test']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.association_name.0', 'An application for this association is already waiting for review.');
});

it('gives the same answer whether the email has an account or a waiting application', function () {
    User::factory()->staff()->create(['email' => 'taken@example.test']);
    applCreate(['email' => 'waiting@example.test']);

    $account = $this->postJson('/api/association-applications', applPayload(['email' => 'TAKEN@example.test']))
        ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email');
    $waiting = $this->postJson('/api/association-applications', applPayload(['email' => 'Waiting@Example.test']))
        ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email');

    expect($account)->toBe($waiting)
        ->and(implode(' ', $account))->not->toContain('account exists');
});

it('lets an association apply again after a rejection', function () {
    applCreate([
        'association_name' => 'Sagada Inabel Weavers Association',
        'email' => 'maria.dulnuan@example.test',
        'status' => 'rejected',
        'decision_note' => 'No proof of registration.',
        'password_hash' => null,
    ]);

    $this->postJson('/api/association-applications', applPayload())->assertCreated();

    expect(AssociationApplication::pending()->count())->toBe(1);
});

it('answers a bot that fills the hidden field as if it worked, and saves nothing', function () {
    $this->postJson('/api/association-applications', applPayload(['website' => 'http://spam.example']))
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    expect(AssociationApplication::count())->toBe(0);
});

it('limits applications to ten an hour for each address', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/association-applications', [])->assertUnprocessable();
    }

    $this->postJson('/api/association-applications', applPayload())->assertTooManyRequests();
});

// ---- public: checking the result --------------------------------------------

it('tells the applicant a waiting application is pending, whatever the capitals', function () {
    $application = applCreate(['email' => 'Maria@Example.test']);

    $response = $this->postJson('/api/association-applications/status', [
        'reference' => strtolower($application->reference),
        'email' => 'MARIA@example.test',
    ])->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('association_name', $application->association_name)
        ->assertJsonPath('decision_note', null);

    expect($response->getContent())->not->toContain('password_hash')
        ->and($response->getContent())->not->toContain('contact_phone');
});

it('gives the same answer for a wrong reference, a wrong email and an unknown one', function () {
    $application = applCreate(['email' => 'maria@example.test']);

    $messages = collect([
        ['reference' => $application->reference, 'email' => 'someone.else@example.test'],
        ['reference' => 'AP-20261022-ZZZZZ', 'email' => 'maria@example.test'],
        ['reference' => 'AP-00000000-00000', 'email' => 'nobody@example.test'],
    ])->map(fn ($body) => $this->postJson('/api/association-applications/status', $body)->assertNotFound()->json('message'));

    expect($messages->unique())->toHaveCount(1);
});

it('shows a rejected applicant the reason, and an approved one that they can sign in', function () {
    $rejected = applCreate(['status' => 'rejected', 'decision_note' => 'Registration number could not be confirmed.', 'password_hash' => null]);
    $approved = applCreate(['status' => 'approved', 'password_hash' => null]);

    $this->postJson('/api/association-applications/status', ['reference' => $rejected->reference, 'email' => $rejected->email])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decision_note', 'Registration number could not be confirmed.');

    $this->postJson('/api/association-applications/status', ['reference' => $approved->reference, 'email' => $approved->email])
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('decision_note', null)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'sign in'));
});

it('limits status checks to ten a minute for each address', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/association-applications/status', ['reference' => 'AP-X', 'email' => 'a@example.test'])->assertNotFound();
    }

    $this->postJson('/api/association-applications/status', ['reference' => 'AP-X', 'email' => 'a@example.test'])->assertTooManyRequests();
});

// ---- staff and admin: who sees what -----------------------------------------

it('keeps the applications away from guests and association accounts', function () {
    $application = applCreate();

    $this->getJson('/api/applications')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->association()->create());
    $this->getJson('/api/applications')->assertForbidden();
    $this->postJson("/api/applications/{$application->id}/approve")->assertForbidden();
    $this->postJson("/api/applications/{$application->id}/reject", ['note' => 'No reason at all'])->assertForbidden();
});

it('shows admin every application and staff only their municipality, whatever the capitals', function () {
    applCreate(['municipality' => 'Sagada']);
    applCreate(['municipality' => 'Sagada']);
    applCreate(['municipality' => 'Bontoc']);

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson('/api/applications')->assertOk()->assertJsonCount(3, 'data');

    Sanctum::actingAs(applStaff('sagada'));
    $this->getJson('/api/applications')->assertOk()->assertJsonCount(2, 'data');
});

it('shows staff without a municipality nothing', function () {
    applCreate();

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => null]));

    $this->getJson('/api/applications')->assertOk()->assertJsonCount(0, 'data');
});

it('filters by status and gives the reviewer the contact details but never the password hash', function () {
    applCreate(['contact_phone' => '09181112222', 'email' => 'reviewme@example.test']);
    applCreate(['status' => 'rejected', 'decision_note' => 'Duplicate.', 'password_hash' => null]);

    Sanctum::actingAs(applStaff());

    $response = $this->getJson('/api/applications?status=pending')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/applications?status=rejected')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/applications?status=bogus')->assertUnprocessable();

    $item = $response->json('data.0');
    expect($item['contact_phone'])->toBe('09181112222')
        ->and($item['email'])->toBe('reviewme@example.test')
        ->and($response->getContent())->not->toContain('password_hash');
});

it('warns the reviewer when a pending application now clashes with an existing name or email', function () {
    applCreate(['association_name' => "Rose Ann's Loom", 'email' => 'clash@example.test']);
    Association::factory()->create(['name' => 'rose anns loom']);
    User::factory()->staff()->create(['email' => 'clash@example.test']);

    Sanctum::actingAs(User::factory()->admin()->create());

    $conflict = $this->getJson('/api/applications')->assertOk()->json('data.0.conflict');
    expect($conflict)->toHaveCount(2);
});

// ---- staff and admin: approving ---------------------------------------------

it('approves: creates the association and an account that signs in with the applicant password', function () {
    $application = applCreate([
        'association_name' => 'Sagada Inabel Weavers Association',
        'contact_name' => 'Maria Dulnuan',
        'email' => 'maria.dulnuan@example.test',
    ]);
    $staff = applStaff();
    Sanctum::actingAs($staff);

    $this->postJson("/api/applications/{$application->id}/approve")
        ->assertOk()
        ->assertJsonPath('application.status', 'approved')
        ->assertJsonPath('application.decided_by', $staff->name);

    $association = Association::firstOrFail();
    expect($association->name)->toBe('Sagada Inabel Weavers Association')
        ->and($association->municipality)->toBe('Sagada')
        ->and($association->status)->toBe('active');

    $user = User::where('email', 'maria.dulnuan@example.test')->firstOrFail();
    expect($user->role)->toBe('association')
        ->and($user->association_id)->toBe($association->id)
        ->and($user->name)->toBe('Maria Dulnuan')
        ->and($user->status)->toBe('active')
        ->and($user->must_change_password)->toBeFalse();

    $application->refresh();
    expect($application->status)->toBe('approved')
        ->and($application->decided_by)->toBe($staff->id)
        ->and($application->decided_at)->not->toBeNull()
        ->and($application->association_id)->toBe($association->id)
        ->and($application->user_id)->toBe($user->id)
        ->and($application->password_hash)->toBeNull();

    // A fresh request, as the login page sends it: no token, the applicant's own password.
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', '')->postJson('/api/login', ['email' => 'maria.dulnuan@example.test', 'password' => APPL_PASSWORD])
        ->assertOk()
        ->assertJsonPath('user.role', 'association')
        ->assertJsonPath('user.must_change_password', false);
});

it('stores the applicant hash as it was made, even if the server hashing cost changed since', function () {
    // Made at a different cost than the server uses now: a plain assignment would hash it again
    // and the applicant could never sign in.
    $hash = Hash::driver('bcrypt')->make(APPL_PASSWORD, ['rounds' => 6]);
    expect(Hash::needsRehash($hash))->toBeTrue();

    $application = applCreate(['password_hash' => $hash, 'email' => 'old.cost@example.test']);

    Sanctum::actingAs(applStaff());
    $this->postJson("/api/applications/{$application->id}/approve")->assertOk();

    expect(User::where('email', 'old.cost@example.test')->firstOrFail()->getAttributes()['password'])->toBe($hash);

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', '')->postJson('/api/login', ['email' => 'old.cost@example.test', 'password' => APPL_PASSWORD])->assertOk();
});

it('stops staff approving an application from another municipality', function () {
    $application = applCreate(['municipality' => 'Bontoc']);

    Sanctum::actingAs(applStaff('Sagada'));
    $this->postJson("/api/applications/{$application->id}/approve")->assertForbidden();

    expect($application->refresh()->status)->toBe('pending')
        ->and(Association::count())->toBe(0);
});

it('lets admin approve an application from any municipality', function () {
    $application = applCreate(['municipality' => 'Tadian']);

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->postJson("/api/applications/{$application->id}/approve")->assertOk();

    expect(Association::firstOrFail()->municipality)->toBe('Tadian');
});

it('does not decide the same application twice', function () {
    $application = applCreate();
    Sanctum::actingAs(applStaff());

    $this->postJson("/api/applications/{$application->id}/approve")->assertOk();
    $this->postJson("/api/applications/{$application->id}/approve")->assertUnprocessable();
    $this->postJson("/api/applications/{$application->id}/reject", ['note' => 'Changed my mind.'])->assertUnprocessable();

    expect(Association::count())->toBe(1)
        ->and($application->refresh()->status)->toBe('approved');
});

it('stops an approval when the name was registered after the applicant applied', function () {
    $application = applCreate(['association_name' => "Rose Ann's Loom"]);
    Association::factory()->create(['name' => 'Rose Anns Loom']);

    Sanctum::actingAs(applStaff());
    $before = User::count();
    $this->postJson("/api/applications/{$application->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('association_name');

    expect($application->refresh()->status)->toBe('pending')
        ->and(User::count())->toBe($before);
});

it('stops an approval when the email now belongs to an account', function () {
    $application = applCreate(['email' => 'late@example.test']);
    User::factory()->staff()->create(['email' => 'Late@Example.test']);

    Sanctum::actingAs(applStaff());
    $this->postJson("/api/applications/{$application->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($application->refresh()->status)->toBe('pending')
        ->and(Association::count())->toBe(0);
});

it('creates nothing when the account cannot be saved', function () {
    $application = applCreate();
    Sanctum::actingAs(applStaff());
    User::creating(fn () => throw new RuntimeException('simulated failure'));

    $this->postJson("/api/applications/{$application->id}/approve")->assertServerError();

    User::flushEventListeners();

    expect(Association::count())->toBe(0)
        ->and($application->refresh()->status)->toBe('pending')
        ->and($application->password_hash)->not->toBeNull();
});

// ---- staff and admin: rejecting ---------------------------------------------

it('rejects with a reason the applicant can read, and deletes the saved password hash', function () {
    $application = applCreate(['email' => 'nope@example.test']);
    $staff = applStaff();
    Sanctum::actingAs($staff);

    $this->postJson("/api/applications/{$application->id}/reject", ['note' => 'We could not confirm your registration number.'])
        ->assertOk()
        ->assertJsonPath('application.status', 'rejected');

    $application->refresh();
    expect($application->status)->toBe('rejected')
        ->and($application->decision_note)->toBe('We could not confirm your registration number.')
        ->and($application->decided_by)->toBe($staff->id)
        ->and($application->password_hash)->toBeNull()
        ->and(Association::count())->toBe(0)
        ->and(User::where('email', 'nope@example.test')->exists())->toBeFalse();

    $this->postJson('/api/association-applications/status', ['reference' => $application->reference, 'email' => 'nope@example.test'])
        ->assertOk()
        ->assertJsonPath('decision_note', 'We could not confirm your registration number.');
});

it('needs a reason to reject', function (array $body) {
    $application = applCreate();
    Sanctum::actingAs(applStaff());

    $this->postJson("/api/applications/{$application->id}/reject", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    expect($application->refresh()->status)->toBe('pending');
})->with([
    'no note' => [[]],
    'blank note' => [['note' => '    ']],
    'too short' => [['note' => 'no']],
    'too long' => [['note' => str_repeat('x', 501)]],
]);

it('stops staff rejecting an application from another municipality', function () {
    $application = applCreate(['municipality' => 'Bontoc']);

    Sanctum::actingAs(applStaff('Sagada'));
    $this->postJson("/api/applications/{$application->id}/reject", ['note' => 'Not in our municipality.'])->assertForbidden();

    expect($application->refresh()->status)->toBe('pending');
});
