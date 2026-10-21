<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\SuspiciousReport;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A revoked tag: every scan of it is flagged for a reason that is not an incomplete read. */
function reportFlaggedTag(): NfcTag
{
    return NfcTag::factory()->revoked()->create();
}

/** A tag in an association of the given municipality. */
function reportTagIn(string $municipality): NfcTag
{
    $association = Association::factory()->create(['municipality' => $municipality]);
    $weaver = Weaver::factory()->create(['association_id' => $association->id]);
    $product = Product::factory()->create(['weaver_id' => $weaver->id]);

    return NfcTag::factory()->revoked()->create(['product_id' => $product->id]);
}

function reportScan(NfcTag $tag, string $device = 'phone-1', array $extra = []): void
{
    test()->postJson('/api/verify', array_merge(['uid' => $tag->uid, 'device_id' => $device], $extra))->assertOk();
}

function reportPayload(NfcTag $tag, array $overrides = []): array
{
    return array_merge([
        'uid' => $tag->uid,
        'device_id' => 'phone-1',
        'app_version' => '1.0.0',
        'location' => 'Souvenir stall, Bontoc public market',
    ], $overrides);
}

// ---- the prompt: verify tells the app when to offer a report ----------------

it('lets the app offer a report after a flagged scan', function () {
    $tag = reportFlaggedTag();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonPath('can_report', true)
        ->assertJsonMissingPath('retry')
        ->assertJsonMissingPath('flag_reason');
});

it('does not offer a report after an authenticated or unregistered scan', function () {
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonMissingPath('can_report');

    $this->postJson('/api/verify', ['uid' => '04AABBCCDDEEFF'])
        ->assertOk()
        ->assertJsonMissingPath('can_report');
});

it('asks for another tap when a secured tag was read without its counter, and offers a report on the second flag', function () {
    $tag = NfcTag::factory()->secured()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonPath('can_report', false)
        ->assertJsonPath('retry', true);

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonPath('can_report', true)
        ->assertJsonMissingPath('retry');
});

it('starts the count again after the tag authenticates in between', function () {
    $tag = NfcTag::factory()->secured()->create();

    reportScan($tag);                                  // read without counter: flagged
    reportScan($tag, 'phone-1', ['counter' => 11]);    // read again properly: authenticated

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
        ->assertOk()
        ->assertJsonPath('can_report', false)
        ->assertJsonPath('retry', true);

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);
});

it('does not offer a report once the tag authenticates again', function () {
    $tag = NfcTag::factory()->secured()->create();

    reportScan($tag);
    reportScan($tag);                                  // second flag: reporting is open
    reportScan($tag, 'phone-1', ['counter' => 11]);    // authenticated

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);
});

it('does not let a flag from another phone count toward the second flag', function () {
    $tag = NfcTag::factory()->secured()->create();

    reportScan($tag, 'phone-1');

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-2'])
        ->assertOk()
        ->assertJsonPath('can_report', false)
        ->assertJsonPath('retry', true);
});

it('keeps the normal flagged message for real flags', function () {
    $tag = reportFlaggedTag();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'report it to DTI-Mountain Province'));
});

// ---- sending a report -----------------------------------------------------------

it('stores a report with the reason copied from the flagged scan', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag, [
        'seen_on' => now()->toDateString(),
        'note' => 'Seller said it was a Bontoc weave but the tag looked taped on.',
        'contact' => '0917 000 0000',
    ]))
        ->assertCreated()
        ->assertJsonPath('duplicate', false)
        ->assertJsonStructure(['reference', 'message']);

    $report = SuspiciousReport::firstOrFail();

    expect($report->uid)->toBe($tag->uid)
        ->and($report->nfc_tag_id)->toBe($tag->id)
        ->and($report->flag_reason)->toBe('tag_revoked')
        ->and($report->status)->toBe('new')
        ->and($report->location)->toBe('Souvenir stall, Bontoc public market')
        ->and($report->contact)->toBe('0917 000 0000')
        ->and($report->reporter_key)->toBe('phone-1')
        ->and($report->reference)->toStartWith('SR-');
});

it('accepts an anonymous report with only the place', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();

    $this->assertDatabaseHas('suspicious_reports', [
        'uid' => $tag->uid,
        'contact' => null,
        'note' => null,
        'seen_on' => null,
    ]);
});

it('accepts UIDs with separators and lowercase letters', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag, ['uid' => implode(':', str_split(strtolower($tag->uid), 2))]))
        ->assertCreated();
});

it('does not change or revoke the tag when a report is made', function () {
    $tag = NfcTag::factory()->create();
    $tag->product->update(['status' => 'inactive']);
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();

    expect($tag->fresh()->status)->toBe('active');
});

it('refuses a report when this phone has no flagged scan of the tag', function () {
    $tag = reportFlaggedTag();

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);

    reportScan($tag, 'phone-2');
    $this->postJson('/api/reports', reportPayload($tag, ['device_id' => 'phone-1']))->assertStatus(422);

    $this->assertDatabaseCount('suspicious_reports', 0);
});

it('refuses a report for a tag that was authenticated', function () {
    $tag = NfcTag::factory()->create();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);
});

it('refuses a report when the only flag was one incomplete read', function () {
    $tag = NfcTag::factory()->secured()->create();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);

    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
});

it('refuses a report when the flagged scan is too old', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->travel(61)->minutes();

    $this->postJson('/api/reports', reportPayload($tag))->assertStatus(422);
});

it('does not take the reason from the app', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag, ['flag_reason' => 'counter_rollback', 'status' => 'verified']))
        ->assertCreated();

    $this->assertDatabaseHas('suspicious_reports', ['flag_reason' => 'tag_revoked', 'status' => 'new']);
});

it('keeps one report per phone and tag per day, and a second phone adds its own', function () {
    $tag = reportFlaggedTag();
    reportScan($tag, 'phone-1');
    reportScan($tag, 'phone-2');

    $first = $this->postJson('/api/reports', reportPayload($tag))->assertCreated()->json('reference');

    $this->postJson('/api/reports', reportPayload($tag))
        ->assertOk()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('reference', $first);

    $this->assertDatabaseCount('suspicious_reports', 1);

    $this->postJson('/api/reports', reportPayload($tag, ['device_id' => 'phone-2']))->assertCreated();

    $this->assertDatabaseCount('suspicious_reports', 2);
});

it('lets the same phone report again the next day', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();

    $this->travel(25)->hours();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $this->assertDatabaseCount('suspicious_reports', 2);
});

it('validates the report fields', function (array $overrides, string $field) {
    $tag = reportFlaggedTag();
    reportScan($tag);

    $this->postJson('/api/reports', reportPayload($tag, $overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'no place' => [['location' => ''], 'location'],
    'place too short' => [['location' => 'ab'], 'location'],
    'place too long' => [['location' => str_repeat('a', 151)], 'location'],
    'date in the future' => [['seen_on' => '2999-01-01'], 'seen_on'],
    'date in a wrong format' => [['seen_on' => '21/10/2026'], 'seen_on'],
    'note too long' => [['note' => str_repeat('a', 1001)], 'note'],
    'contact too long' => [['contact' => str_repeat('a', 121)], 'contact'],
    'bad uid' => [['uid' => '04A1'], 'uid'],
]);

it('rate limits the public report endpoint per IP address', function () {
    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/reports', [])->assertStatus(422);
    }

    $this->postJson('/api/reports', [])->assertStatus(429);
});

// ---- review on the dashboards ---------------------------------------------------------

it('shows admin every report and staff only reports of their municipality', function () {
    $bontoc = reportTagIn('Bontoc');
    $sagada = reportTagIn('Sagada');
    reportScan($bontoc);
    reportScan($sagada);
    $this->postJson('/api/reports', reportPayload($bontoc))->assertCreated();
    $this->postJson('/api/reports', reportPayload($sagada))->assertCreated();

    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson('/api/reports')->assertOk()->assertJsonCount(2, 'data');

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));
    $this->getJson('/api/reports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uid', $bontoc->uid)
        ->assertJsonPath('data.0.association.municipality', 'Bontoc')
        ->assertJsonPath('data.0.flag_reason', 'tag_revoked')
        ->assertJsonPath('data.0.same_tag_reports', 1);
});

it('counts the reports made about the same tag', function () {
    $tag = reportFlaggedTag();
    reportScan($tag, 'phone-1');
    reportScan($tag, 'phone-2');
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $this->postJson('/api/reports', reportPayload($tag, ['device_id' => 'phone-2']))->assertCreated();

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/reports')
        ->assertOk()
        ->assertJsonPath('data.0.same_tag_reports', 2);
});

it('filters reports by status', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    SuspiciousReport::query()->update(['status' => 'dismissed']);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/reports?status=new')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/reports?status=dismissed')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/reports?status=nonsense')->assertStatus(422);
});

it('keeps reports away from the public and from association users', function () {
    $this->getJson('/api/reports')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->association()->create());

    $this->getJson('/api/reports')->assertForbidden();
});

it('lets staff review a report in their municipality and records who did it', function () {
    $tag = reportTagIn('Bontoc');
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $report = SuspiciousReport::firstOrFail();

    $staff = User::factory()->staff()->create(['municipality' => 'Bontoc']);
    Sanctum::actingAs($staff);

    $this->patchJson("/api/reports/{$report->id}", ['status' => 'under_review', 'review_note' => 'Asked the stall owner.'])
        ->assertOk()
        ->assertJsonPath('status', 'under_review')
        ->assertJsonPath('reviewed_by', $staff->name);

    $report->refresh();

    expect($report->status)->toBe('under_review')
        ->and($report->review_note)->toBe('Asked the stall owner.')
        ->and($report->reviewed_by)->toBe($staff->id)
        ->and($report->reviewed_at)->not->toBeNull();
});

it('does not revoke or change the tag when a report is marked verified or referred', function () {
    $tag = NfcTag::factory()->create();
    $tag->product->update(['status' => 'inactive']);
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $report = SuspiciousReport::firstOrFail();

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/reports/{$report->id}", ['status' => 'verified'])->assertOk();
    $this->patchJson("/api/reports/{$report->id}", ['status' => 'referred'])->assertOk();

    expect($tag->fresh()->status)->toBe('active');
});

it('stops staff from reviewing a report outside their municipality', function () {
    $tag = reportTagIn('Sagada');
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $report = SuspiciousReport::firstOrFail();

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->patchJson("/api/reports/{$report->id}", ['status' => 'dismissed'])->assertForbidden();

    expect($report->fresh()->status)->toBe('new');
});

it('rejects an unknown status', function () {
    $tag = reportFlaggedTag();
    reportScan($tag);
    $this->postJson('/api/reports', reportPayload($tag))->assertCreated();
    $report = SuspiciousReport::firstOrFail();

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->patchJson("/api/reports/{$report->id}", ['status' => 'fake'])->assertStatus(422);
    $this->patchJson("/api/reports/{$report->id}", [])->assertStatus(422);
});
