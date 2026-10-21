<?php

use App\Models\Association;
use App\Models\AssociationApplication;
use App\Models\NfcTag;
use App\Models\Product;
use App\Models\ScanLog;
use App\Models\SuspiciousReport;
use App\Models\User;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A tag in a new association of the given municipality (or in the given association). */
function attnTag(string $municipality = 'Bontoc', ?Association $association = null): NfcTag
{
    $association ??= Association::factory()->create(['municipality' => $municipality]);
    $weaver = Weaver::factory()->create(['association_id' => $association->id]);
    $product = Product::factory()->create(['weaver_id' => $weaver->id, 'title' => 'Wrap skirt '.$association->id]);

    return NfcTag::factory()->create(['product_id' => $product->id]);
}

function attnReport(NfcTag $tag, string $status = 'new', ?DateTimeInterface $at = null): SuspiciousReport
{
    static $n = 0;
    $n++;

    $report = SuspiciousReport::create([
        'reference' => 'SR-TEST-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'uid' => $tag->uid,
        'nfc_tag_id' => $tag->id,
        'flag_reason' => 'many_devices',
        'reporter_key' => 'phone-'.$n,
        'location' => 'Bontoc public market',
        'note' => 'private note '.$n,
        'contact' => 'reporter'.$n.'@example.test',
        'status' => $status,
    ]);

    if ($at) {
        $report->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    return $report;
}

function attnScan(NfcTag $tag, string $result = 'suspicious', ?DateTimeInterface $at = null): ScanLog
{
    $log = ScanLog::create([
        'uid' => $tag->uid,
        'nfc_tag_id' => $tag->id,
        'result' => $result,
        'device_id' => 'phone-x',
        'flag_reason' => $result === 'suspicious' ? 'many_devices' : null,
    ]);

    if ($at) {
        $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    return $log;
}

// ---- who may call it --------------------------------------------------------

it('needs a signed-in user', function () {
    $this->getJson('/api/attention')->assertUnauthorized();
});

it('refuses a deactivated account', function () {
    Sanctum::actingAs(User::factory()->admin()->inactive()->create());

    $this->getJson('/api/attention')->assertForbidden();
});

it('stays closed until the starting password is replaced', function () {
    Sanctum::actingAs(User::factory()->admin()->mustChangePassword()->create());

    $this->getJson('/api/attention')
        ->assertForbidden()
        ->assertJsonPath('code', 'password_change_required');
});

// ---- admin ------------------------------------------------------------------

it('counts every new report for admin and ignores reports already handled', function () {
    $bontoc = attnTag('Bontoc');
    $sagada = attnTag('Sagada');

    attnReport($bontoc);
    attnReport($sagada);
    attnReport($sagada, 'under_review');
    attnReport($sagada, 'verified');
    attnReport($sagada, 'dismissed');
    attnReport($sagada, 'referred');

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('new_reports.count', 2)
        ->assertJsonCount(2, 'new_reports.latest');
});

it('keeps a new report in the count however old it is', function () {
    attnReport(attnTag(), 'new', now()->subDays(30));

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')->assertOk()->assertJsonPath('new_reports.count', 1);
});

it('counts suspicious scans of the last 24 hours only, and only suspicious ones', function () {
    $tag = attnTag();

    attnScan($tag, 'suspicious', now()->subHours(23));
    attnScan($tag, 'suspicious', now()->subMinutes(5));
    attnScan($tag, 'suspicious', now()->subHours(25));
    attnScan($tag, 'authenticated');
    attnScan($tag, 'unregistered');

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('window_hours', 24)
        ->assertJsonPath('suspicious_scans.count', 2)
        ->assertJsonCount(2, 'suspicious_scans.latest');
});

it('lets the window be changed in the settings', function () {
    $tag = attnTag();
    attnScan($tag, 'suspicious', now()->subHours(3));

    config(['weaver.attention_hours' => 2]);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('window_hours', 2)
        ->assertJsonPath('suspicious_scans.count', 0);
});

it('lists at most five of the newest items but counts all of them', function () {
    $tag = attnTag();

    foreach (range(1, 7) as $i) {
        attnReport($tag);
        attnScan($tag);
    }

    Sanctum::actingAs(User::factory()->admin()->create());

    $response = $this->getJson('/api/attention')->assertOk();

    $response->assertJsonPath('new_reports.count', 7)->assertJsonCount(5, 'new_reports.latest');
    $response->assertJsonPath('suspicious_scans.count', 7)->assertJsonCount(5, 'suspicious_scans.latest');

    $ids = collect($response->json('new_reports.latest'))->pluck('id')->all();
    expect($ids)->toBe(collect($ids)->sortDesc()->values()->all())
        ->and($ids[0])->toBe(SuspiciousReport::max('id'));
});

it('shows enough to recognise an item but never the reporter private details', function () {
    $tag = attnTag();
    $report = attnReport($tag);
    attnScan($tag);

    Sanctum::actingAs(User::factory()->admin()->create());

    $response = $this->getJson('/api/attention')->assertOk();

    $item = $response->json('new_reports.latest.0');
    expect($item)->toHaveKeys(['id', 'reference', 'uid', 'product', 'flag_reason', 'location', 'created_at'])
        ->and($item['reference'])->toBe($report->reference)
        ->and($item['product'])->toBe($tag->product->title);

    $json = $response->getContent();
    expect($json)->not->toContain('private note')
        ->and($json)->not->toContain('@example.test')
        ->and($json)->not->toContain('reporter_key')
        ->and($json)->not->toContain('phone-');
});

it('still counts a report whose tag was deleted, for admin', function () {
    $tag = attnTag();
    attnReport($tag);
    $tag->delete();

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('new_reports.count', 1)
        ->assertJsonPath('new_reports.latest.0.product', null);
});

// ---- staff ------------------------------------------------------------------

it('limits staff to the new reports of their own municipality', function () {
    attnReport(attnTag('Bontoc'));
    attnReport(attnTag('Bontoc'));
    attnReport(attnTag('Sagada'));
    attnReport(attnTag('Bontoc'), 'dismissed');

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('new_reports.count', 2)
        ->assertJsonCount(2, 'new_reports.latest');
});

it('gives staff no scan numbers, because staff have no scan log to open', function () {
    $tag = attnTag('Bontoc');
    attnScan($tag);

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Bontoc']));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('suspicious_scans', null);
});

it('shows staff without a municipality nothing', function () {
    attnReport(attnTag('Bontoc'));

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => null]));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('new_reports.count', 0)
        ->assertJsonCount(0, 'new_reports.latest');
});

// ---- association ------------------------------------------------------------

it('limits an association to suspicious scans of its own tags', function () {
    $mine = Association::factory()->create(['municipality' => 'Bontoc']);
    $other = Association::factory()->create(['municipality' => 'Bontoc']);

    attnScan(attnTag('Bontoc', $mine));
    attnScan(attnTag('Bontoc', $mine));
    attnScan(attnTag('Bontoc', $mine), 'authenticated');
    attnScan(attnTag('Bontoc', $mine), 'suspicious', now()->subHours(30));
    attnScan(attnTag('Bontoc', $other));

    Sanctum::actingAs(User::factory()->association()->create(['association_id' => $mine->id]));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('suspicious_scans.count', 2)
        ->assertJsonCount(2, 'suspicious_scans.latest');
});

it('does not count scans of unknown tags for an association', function () {
    $mine = Association::factory()->create();
    ScanLog::create(['uid' => '04AABBCCDDEEFF', 'nfc_tag_id' => null, 'result' => 'suspicious', 'flag_reason' => 'many_devices']);

    Sanctum::actingAs(User::factory()->association()->create(['association_id' => $mine->id]));

    $this->getJson('/api/attention')->assertOk()->assertJsonPath('suspicious_scans.count', 0);
});

it('gives an association no report numbers, because it has no Reports tab', function () {
    $mine = Association::factory()->create();
    attnReport(attnTag('Bontoc', $mine));

    Sanctum::actingAs(User::factory()->association()->create(['association_id' => $mine->id]));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('new_reports', null);
});

it('refuses an association account that is not linked to an association', function () {
    Sanctum::actingAs(User::factory()->association()->create(['association_id' => null]));

    $this->getJson('/api/attention')
        ->assertForbidden()
        ->assertJsonPath('message', 'Association account is not linked to an association.');
});

// ---- association applications -----------------------------------------------

function attnApplication(string $municipality = 'Sagada', string $status = 'pending'): AssociationApplication
{
    static $n = 0;
    $n++;

    return AssociationApplication::create([
        'reference' => 'AP-ATTN-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'association_name' => 'Waiting group '.$n,
        'municipality' => $municipality,
        'contact_name' => 'Private Contact',
        'contact_phone' => '09170001111',
        'email' => 'private'.$n.'@example.test',
        'password_hash' => Hash::make('Tg7#kLm92xQv'),
        'status' => $status,
        'consented_at' => now(),
        'notice_version' => '2026-10',
    ]);
}

it('counts waiting applications for admin, whatever the age, and ignores decided ones', function () {
    attnApplication('Sagada');
    attnApplication('Bontoc');
    $old = attnApplication('Tadian');
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    attnApplication('Sagada', 'approved');
    attnApplication('Sagada', 'rejected');

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('pending_applications.count', 3)
        ->assertJsonCount(3, 'pending_applications.latest');
});

it('limits staff to the waiting applications of their own municipality', function () {
    attnApplication('Sagada');
    attnApplication('sagada');
    attnApplication('Bontoc');

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => 'Sagada']));

    $this->getJson('/api/attention')
        ->assertOk()
        ->assertJsonPath('pending_applications.count', 2);
});

it('shows staff without a municipality no applications', function () {
    attnApplication('Sagada');

    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => null]));

    $this->getJson('/api/attention')->assertOk()->assertJsonPath('pending_applications.count', 0);
});

it('gives an association no application numbers, and never exposes the applicant details', function () {
    attnApplication('Sagada');

    Sanctum::actingAs(User::factory()->association()->create());
    $this->getJson('/api/attention')->assertOk()->assertJsonPath('pending_applications', null);

    Sanctum::actingAs(User::factory()->admin()->create());
    $json = $this->getJson('/api/attention')->assertOk()->getContent();

    expect($json)->not->toContain('private')
        ->and($json)->not->toContain('09170001111')
        ->and($json)->not->toContain('password_hash')
        ->and($json)->not->toContain('Private Contact');
});
