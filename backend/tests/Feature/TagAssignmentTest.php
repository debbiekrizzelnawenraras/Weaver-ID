<?php

use App\Models\NfcTag;
use App\Models\TagAlert;
use App\Models\TagAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function assignAdmin(): User
{
    return User::factory()->admin()->create();
}

function receiveAs(string $municipality, array $uids, string $code = 'R-1')
{
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => $municipality]));

    return test()->postJson('/api/inventory/batches', [
        'code' => $code, 'received_on' => now()->toDateString(), 'uids' => $uids,
    ]);
}

it('lets the administrator assign tags to a Negosyo Center', function () {
    Sanctum::actingAs(assignAdmin());

    $this->postJson('/api/admin/tag-assignments', [
        'municipality' => 'Bontoc', 'delivery_ref' => 'DL-001', 'uids' => "04AA000000000001\n04AA000000000002",
    ])->assertCreated();

    expect(TagAssignment::where('municipality', 'Bontoc')->whereNull('received_at')->count())->toBe(2);
    $this->getJson('/api/admin/tag-assignments')->assertOk()
        ->assertJsonPath('municipalities.0.municipality', 'Bontoc')
        ->assertJsonPath('municipalities.0.waiting', 2);
});

it('keeps delivery lists away from staff and associations', function () {
    Sanctum::actingAs(User::factory()->staff()->create());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertForbidden();
    $this->getJson('/api/admin/tag-assignments')->assertForbidden();

    Sanctum::actingAs(User::factory()->association()->create());
    $this->getJson('/api/admin/tag-assignments')->assertForbidden();
});

it('refuses to assign a tag twice or one already received by another center', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertCreated();
    NfcTag::factory()->inStock('Sagada')->create(['uid' => '04AA000000000009']);

    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Sagada', 'uids' => ['04AA000000000001']])
        ->assertUnprocessable()->assertJsonValidationErrors('uids');
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000009']])
        ->assertUnprocessable()->assertJsonValidationErrors('uids');
});

it('lets the right center receive its tags and marks them received', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertCreated();

    receiveAs('Bontoc', ['04AA000000000001'])->assertCreated();

    expect(TagAssignment::firstOrFail()->received_at)->not->toBeNull()
        ->and(TagAlert::count())->toBe(0);
});

it('refuses a tag at the wrong center, receives nothing and alerts the administrator', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001', '04AA000000000002']])->assertCreated();

    receiveAs('Sagada', ['04AA000000000001', '04AA000000000003'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uids');

    expect(NfcTag::count())->toBe(0);
    $alert = TagAlert::sole();
    expect($alert->uid)->toBe('04AA000000000001')
        ->and($alert->assigned_municipality)->toBe('Bontoc')
        ->and($alert->attempted_municipality)->toBe('Sagada');

    // Trying again does not pile up alerts.
    receiveAs('Sagada', ['04AA000000000001'], 'R-2')->assertUnprocessable();
    expect(TagAlert::count())->toBe(1);

    Sanctum::actingAs(assignAdmin());
    $this->getJson('/api/attention')->assertOk()->assertJsonPath('wrong_center_tags.count', 1);
    $this->getJson('/api/admin/tag-assignments')->assertOk()->assertJsonPath('open_alerts', 1)
        ->assertJsonPath('alerts.0.attempted_municipality', 'Sagada');
});

it('lets the administrator move an alerted tag to the center that has it, which can then receive it', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertCreated();
    receiveAs('Sagada', ['04AA000000000001'])->assertUnprocessable();
    $alert = TagAlert::sole();

    Sanctum::actingAs(assignAdmin());
    $this->postJson("/api/admin/tag-alerts/{$alert->id}/move")->assertOk();

    expect($alert->fresh()->status)->toBe('moved')
        ->and(TagAssignment::firstOrFail()->municipality)->toBe('Sagada');
    receiveAs('Sagada', ['04AA000000000001'], 'R-3')->assertCreated();
});

it('lets the administrator dismiss an alert, and the tag stays on its list', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertCreated();
    receiveAs('Sagada', ['04AA000000000001'])->assertUnprocessable();
    $alert = TagAlert::sole();

    Sanctum::actingAs(assignAdmin());
    $this->postJson("/api/admin/tag-alerts/{$alert->id}/dismiss")->assertOk();
    $this->postJson("/api/admin/tag-alerts/{$alert->id}/dismiss")->assertUnprocessable();

    expect($alert->fresh()->status)->toBe('dismissed');
    receiveAs('Sagada', ['04AA000000000001'], 'R-4')->assertUnprocessable();
});

it('still receives tags that are on no delivery list', function () {
    receiveAs('Sagada', ['04AA000000000005'])->assertCreated();
    expect(TagAlert::count())->toBe(0);
});

it('does not move a tag that a center already received', function () {
    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments', ['municipality' => 'Bontoc', 'uids' => ['04AA000000000001']])->assertCreated();
    receiveAs('Bontoc', ['04AA000000000001'])->assertCreated();

    Sanctum::actingAs(assignAdmin());
    $this->postJson('/api/admin/tag-assignments/move', ['municipality' => 'Sagada', 'uids' => ['04AA000000000001']])
        ->assertUnprocessable();
});
