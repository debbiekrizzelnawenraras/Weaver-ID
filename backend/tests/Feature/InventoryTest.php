<?php

use App\Models\Association;
use App\Models\NfcTag;
use App\Models\NfcTagEvent;
use App\Models\Product;
use App\Models\TagBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function invStaff(string $municipality = 'Bontoc'): User
{
    return User::factory()->staff()->create(['municipality' => $municipality]);
}

function invBatch(array|string $uids, string $code = 'B-2026-01'): array
{
    return ['code' => $code, 'supplier' => 'Sample supplier', 'received_on' => now()->toDateString(), 'uids' => $uids];
}

// ---- receiving ----------------------------------------------------------------

it('receives a batch into the Negosyo Center stock and records who received it', function () {
    $staff = invStaff();
    Sanctum::actingAs($staff);

    $this->postJson('/api/inventory/batches', invBatch(['04:aa:00:00:00:00:00:01', '04AA000000000002']))
        ->assertCreated();

    $batch = TagBatch::firstOrFail();
    expect($batch->received_by)->toBe($staff->id)
        ->and(NfcTag::where('tag_batch_id', $batch->id)->where('status', 'in_stock')->count())->toBe(2)
        ->and(NfcTag::whereNotNull('association_id')->count())->toBe(0)
        ->and(NfcTagEvent::where('action', 'received')->count())->toBe(2);

    $this->assertDatabaseHas('nfc_tags', ['uid' => '04AA000000000001']);
});

it('accepts the UIDs as one text box with a UID on each line', function () {
    Sanctum::actingAs(invStaff());

    $this->postJson('/api/inventory/batches', invBatch(" 04AA000000000001\n04AA000000000002\r\n\n"))->assertCreated();

    expect(NfcTag::count())->toBe(2);
});

it('refuses duplicate, malformed or already known UIDs and saves nothing', function (array $uids) {
    NfcTag::factory()->inStock()->create(['uid' => '04AA000000000009']);
    Sanctum::actingAs(invStaff());

    $this->postJson('/api/inventory/batches', invBatch($uids))->assertUnprocessable();

    // The one batch is the stock tag's own; no new batch was saved.
    expect(TagBatch::count())->toBe(1)->and(NfcTag::count())->toBe(1);
})->with([
    'twice in the list' => [['04AA000000000001', '04AA000000000001']],
    'not hexadecimal' => [['04AA00000000000Z']],
    'already in the inventory' => [['04AA000000000001', '04AA000000000009']],
    'empty' => [[]],
]);

it('refuses a batch code used before', function () {
    Sanctum::actingAs(invStaff());
    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000001']))->assertCreated();

    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000002']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('adds more tags to a recorded batch', function () {
    Sanctum::actingAs(invStaff());
    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000001']))->assertCreated();
    $batch = TagBatch::firstOrFail();

    $this->postJson("/api/inventory/batches/{$batch->id}/tags", ['uids' => ['04AA000000000002']])->assertCreated();

    expect($batch->tags()->count())->toBe(2);
});

// ---- sending --------------------------------------------------------------------

it('sends tags from the stock to an association and records where they went', function () {
    $staff = invStaff('Bontoc');
    $association = Association::factory()->create(['municipality' => 'Bontoc']);
    NfcTag::factory()->inStock()->create(['uid' => '04AA000000000001']);
    NfcTag::factory()->inStock()->create(['uid' => '04AA000000000002']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/inventory/send', ['association_id' => $association->id, 'uids' => ['04AA000000000001']])
        ->assertOk();

    $this->assertDatabaseHas('nfc_tags', ['uid' => '04AA000000000001', 'status' => 'sent', 'association_id' => $association->id, 'sent_by' => $staff->id]);
    $this->assertDatabaseHas('nfc_tags', ['uid' => '04AA000000000002', 'status' => 'in_stock', 'association_id' => null]);
    $this->assertDatabaseHas('nfc_tag_events', ['action' => 'sent', 'association_id' => $association->id, 'user_id' => $staff->id]);
});

it('sends nothing when one of the tags is not in stock', function () {
    $association = Association::factory()->create(['municipality' => 'Bontoc']);
    NfcTag::factory()->inStock()->create(['uid' => '04AA000000000001']);
    NfcTag::factory()->create(['uid' => '04AA000000000002']); // already linked
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson('/api/inventory/send', ['association_id' => $association->id, 'uids' => ['04AA000000000001', '04AA000000000002', '04AA000000000003']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('uids');

    expect(NfcTag::where('uid', '04AA000000000001')->value('status'))->toBe('in_stock');
});

it('does not let staff send tags to an association in another municipality', function () {
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    NfcTag::factory()->inStock()->create(['uid' => '04AA000000000001']);
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson('/api/inventory/send', ['association_id' => $foreign->id, 'uids' => ['04AA000000000001']])
        ->assertForbidden();
});

it('takes an unused tag back into stock', function () {
    $association = Association::factory()->create(['municipality' => 'Bontoc']);
    $tag = NfcTag::factory()->sentTo($association)->create();
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson("/api/inventory/tags/{$tag->id}/return")->assertOk();

    expect($tag->fresh())->status->toBe('in_stock')->association_id->toBeNull();
    $this->assertDatabaseHas('nfc_tag_events', ['nfc_tag_id' => $tag->id, 'action' => 'returned', 'association_id' => $association->id]);
});

it('does not take a linked tag back into stock', function () {
    $tag = NfcTag::factory()->create();
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson("/api/inventory/tags/{$tag->id}/return")->assertUnprocessable();

    expect($tag->fresh()->status)->toBe('active');
});

// ---- damaged or lost ------------------------------------------------------------

it('marks a tag damaged or lost with a note, so it can never be linked', function () {
    $tag = NfcTag::factory()->inStock()->create();
    $product = Product::factory()->create();
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson("/api/inventory/tags/{$tag->id}/damaged", ['note' => 'Cracked when unpacked'])->assertOk();

    expect($tag->fresh()->status)->toBe('damaged');
    $this->assertDatabaseHas('nfc_tag_events', ['nfc_tag_id' => $tag->id, 'action' => 'damaged', 'note' => 'Cracked when unpacked']);

    $this->postJson('/api/nfc-tags', ['uid' => $tag->uid, 'product_id' => $product->id])->assertUnprocessable();
});

it('asks to revoke a linked tag before it is marked damaged', function () {
    $tag = NfcTag::factory()->create();
    Sanctum::actingAs(invStaff('Bontoc'));

    $this->postJson("/api/inventory/tags/{$tag->id}/damaged")->assertUnprocessable();

    expect($tag->fresh()->status)->toBe('active');
});

// ---- who may do what ------------------------------------------------------------

it('lets the administrator look at the inventory but not change it', function () {
    $association = Association::factory()->create();
    $tag = NfcTag::factory()->inStock()->create(['uid' => '04AA000000000001']);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/inventory')->assertOk()->assertJsonPath('totals.in_stock', 1);
    $this->getJson('/api/inventory/tags')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000002']))->assertForbidden();
    $this->postJson('/api/inventory/send', ['association_id' => $association->id, 'uids' => ['04AA000000000001']])->assertForbidden();
    $this->postJson("/api/inventory/tags/{$tag->id}/damaged")->assertForbidden();

    expect($tag->fresh()->status)->toBe('in_stock');
});

it('keeps the inventory away from association accounts and guests', function () {
    $this->getJson('/api/inventory')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->association()->create());
    $this->getJson('/api/inventory')->assertForbidden();
    $this->getJson('/api/inventory/tags')->assertForbidden();
    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000002']))->assertForbidden();
});

// ---- counts ---------------------------------------------------------------------

it('counts tags by status, per batch and per association', function () {
    $staff = invStaff('Bontoc');
    $mine = Association::factory()->create(['municipality' => 'Bontoc']);
    $foreign = Association::factory()->create(['municipality' => 'Besao']);
    $batch = TagBatch::create(['code' => 'B1', 'municipality' => 'Bontoc', 'received_on' => now()->toDateString(), 'received_by' => $staff->id]);

    NfcTag::factory()->inStock()->count(3)->create(['tag_batch_id' => $batch->id]);
    NfcTag::factory()->inStock('Besao')->count(4)->create(); // another Negosyo Center's stock
    NfcTag::factory()->sentTo($mine)->count(2)->create(['tag_batch_id' => $batch->id]);
    NfcTag::factory()->create(['tag_batch_id' => $batch->id, 'product_id' => Product::factory()->create(['association_id' => $mine->id])->id]);
    NfcTag::factory()->sentTo($foreign)->create(['tag_batch_id' => $batch->id]);
    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/inventory')->assertOk();

    // Staff see only their own Negosyo Center's stock and the associations of their municipality.
    $response->assertJsonPath('municipality', 'Bontoc')
        ->assertJsonCount(1, 'batches')
        ->assertJsonPath('totals.in_stock', 3)
        ->assertJsonPath('totals.sent', 2)
        ->assertJsonPath('totals.active', 1)
        ->assertJsonPath('batches.0.code', 'B1')
        ->assertJsonPath('batches.0.counts.in_stock', 3)
        ->assertJsonPath('batches.0.received_by', $staff->name)
        ->assertJsonCount(1, 'associations')
        ->assertJsonPath('associations.0.counts.sent', 2)
        ->assertJsonPath('associations.0.counts.active', 1);

    $this->getJson('/api/inventory/tags?status=sent')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/inventory/tags?status=bogus')->assertUnprocessable();
});

it('shows the inventory numbers in the admin summary', function () {
    NfcTag::factory()->inStock()->count(2)->create();
    NfcTag::factory()->sentTo(Association::factory()->create())->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/admin/summary')
        ->assertOk()
        ->assertJsonPath('tags.in_stock', 2)
        ->assertJsonPath('tags.sent', 1)
        ->assertJsonMissingPath('weavers')
        ->assertJsonPath('associations.total', 1);
});

// ---- one stock per Negosyo Center (flow A, 20 Nov 2026) ----------------------------

it('puts a received batch in the stock of the staff member\'s municipality', function () {
    Sanctum::actingAs(invStaff('Sagada'));

    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000001'], 'S-1'))->assertCreated();

    expect(TagBatch::where('code', 'S-1')->value('municipality'))->toBe('Sagada');
});

it('refuses to receive tags for a staff account without a municipality', function () {
    Sanctum::actingAs(User::factory()->staff()->create(['municipality' => null]));

    $this->postJson('/api/inventory/batches', invBatch(['04AA000000000001']))->assertForbidden();
    expect(NfcTag::count())->toBe(0);
});

it('does not let staff add to, send or link from another Negosyo Center\'s stock', function () {
    $besaoTag = NfcTag::factory()->inStock('Besao')->create(['uid' => '04BE000000000001']);
    $sagada = Association::factory()->create(['municipality' => 'Sagada']);
    $product = Product::factory()->create(['association_id' => $sagada->id]);
    Sanctum::actingAs(invStaff('Sagada'));

    $this->postJson("/api/inventory/batches/{$besaoTag->tag_batch_id}/tags", ['uids' => ['04AA000000000002']])->assertForbidden();
    $this->postJson('/api/inventory/send', ['association_id' => $sagada->id, 'uids' => ['04BE000000000001']])->assertUnprocessable();
    $this->postJson('/api/nfc-tags', ['uid' => '04BE000000000001', 'product_id' => $product->id])->assertUnprocessable();
    $this->getJson('/api/inventory/tags?status=in_stock')->assertOk()->assertJsonCount(0, 'data');

    expect($besaoTag->fresh()->status)->toBe('in_stock');
});

it('shows the administrator the stock of every Negosyo Center', function () {
    NfcTag::factory()->inStock('Bontoc')->count(2)->create();
    NfcTag::factory()->inStock('Sagada')->count(3)->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $rows = collect($this->getJson('/api/inventory')->assertOk()->json('municipalities'))->keyBy('municipality');

    expect($rows['Bontoc']['counts']['in_stock'])->toBe(2)
        ->and($rows['Sagada']['counts']['in_stock'])->toBe(3);
});
