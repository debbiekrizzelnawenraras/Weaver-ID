<?php

use App\Models\NfcTag;
use App\Models\NfcTagEvent;
use App\Models\User;
use App\Support\TagSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['weaver.tag_key' => 'test-tag-key-0123456789abcdef']);
});

// ------------------------------------------------------------ verify: counter rule

it('does not check the counter of a tag that was never secured', function () {
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 3])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');

    expect($tag->fresh()->last_counter)->toBeNull();
});

it('authenticates a secured tag whose counter goes up and remembers it', function () {
    $tag = NfcTag::factory()->secured(10)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 11])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');

    expect($tag->fresh()->last_counter)->toBe(11);
    $this->assertDatabaseHas('scan_logs', ['nfc_tag_id' => $tag->id, 'counter' => 11, 'flag_reason' => null]);
});

it('accepts the same counter value again', function () {
    $tag = NfcTag::factory()->secured(10)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 10])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');
});

it('flags a secured tag whose counter is lower than one already seen', function () {
    $tag = NfcTag::factory()->secured(50)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 4])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonMissingPath('product');

    expect($tag->fresh()->last_counter)->toBe(50);
    $this->assertDatabaseHas('scan_logs', [
        'nfc_tag_id' => $tag->id,
        'result' => 'suspicious',
        'flag_reason' => 'counter_rollback',
    ]);
});

it('flags a secured tag that arrives without a counter', function () {
    $tag = NfcTag::factory()->secured(10)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious');

    $this->assertDatabaseHas('scan_logs', ['nfc_tag_id' => $tag->id, 'flag_reason' => 'counter_missing']);
    expect($tag->fresh()->last_counter)->toBe(10);
});

it('flags a counter that jumps far above the saved one and does not save it', function () {
    config(['weaver.counter_window' => 1000]);
    $tag = NfcTag::factory()->secured(50)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 16000000])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonMissingPath('product');

    expect($tag->fresh()->last_counter)->toBe(50);
    $this->assertDatabaseHas('scan_logs', ['nfc_tag_id' => $tag->id, 'flag_reason' => 'counter_jump']);

    // The forged number did not lock the genuine tag out.
    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 51])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');

    expect($tag->fresh()->last_counter)->toBe(51);
});

it('accepts a counter right at the edge of the window', function () {
    config(['weaver.counter_window' => 1000]);
    $tag = NfcTag::factory()->secured(50)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 1050])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');

    expect($tag->fresh()->last_counter)->toBe(1050);

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 2051])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious');
});

it('computes the counter window', function () {
    expect(TagSecurity::counterJumpTooBig(null, 5000, 1000))->toBeFalse()
        ->and(TagSecurity::counterJumpTooBig(50, 1050, 1000))->toBeFalse()
        ->and(TagSecurity::counterJumpTooBig(50, 1051, 1000))->toBeTrue()
        ->and(TagSecurity::counterJumpTooBig(50, 50, 1000))->toBeFalse()
        ->and(TagSecurity::counterJumpTooBig(50, 51, -5))->toBeTrue();
});

it('records why a revoked tag is suspicious', function () {
    $tag = NfcTag::factory()->revoked()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious');

    $this->assertDatabaseHas('scan_logs', ['nfc_tag_id' => $tag->id, 'flag_reason' => 'tag_revoked']);
});

it('rejects a counter that is not a valid number', function () {
    $tag = NfcTag::factory()->secured()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('counter');

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 16777216])
        ->assertStatus(422)
        ->assertJsonValidationErrors('counter');

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('counter');
});

it('never shows the flag reason to the public', function () {
    $tag = NfcTag::factory()->secured(50)->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'counter' => 1])
        ->assertOk()
        ->assertJsonMissingPath('flag_reason')
        ->assertJsonMissingPath('reason');
});

// ------------------------------------------------------------ write password

it('derives the same password for the same UID and a different one for another UID', function () {
    $a = TagSecurity::credentials('04AABBCCDDEEFF');
    $b = TagSecurity::credentials('04AABBCCDDEEFF');
    $c = TagSecurity::credentials('04AABBCCDDEE00');

    expect($a)->toBe($b)
        ->and($a['password'])->toMatch('/^[0-9A-F]{8}$/')
        ->and($a['pack'])->toMatch('/^[0-9A-F]{4}$/')
        ->and($a['password'])->not->toBe($c['password']);
});

it('refuses to derive a password without a key', function () {
    config(['weaver.tag_key' => null]);

    TagSecurity::credentials('04AABBCCDDEEFF');
})->throws(RuntimeException::class);

it('gives the write password to a user who manages the tag', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secure-key")
        ->assertOk()
        ->assertJsonPath('uid', $tag->uid)
        ->assertJsonPath('password', TagSecurity::credentials($tag->uid)['password'])
        ->assertJsonPath('pack', TagSecurity::credentials($tag->uid)['pack']);

    $this->assertDatabaseHas('nfc_tag_events', ['nfc_tag_id' => $tag->id, 'action' => 'key_issued']);
});

it('does not give the password to someone outside the tag scope', function () {
    $tag = NfcTag::factory()->create();
    Sanctum::actingAs(User::factory()->association()->create());

    $this->postJson("/api/nfc-tags/{$tag->id}/secure-key")->assertForbidden();
    expect(NfcTagEvent::count())->toBe(0);
});

it('does not give the password without a login', function () {
    $tag = NfcTag::factory()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secure-key")->assertUnauthorized();
});

it('does not give a password for a revoked tag', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->revoked()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secure-key")->assertStatus(422);
});

it('says so when the server has no tag key', function () {
    config(['weaver.tag_key' => null]);
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secure-key")->assertStatus(503);
});

// ------------------------------------------------------------ marking a tag as secured

it('marks a tag as secured and stores the counter the desk read', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secured", ['counter' => 7])
        ->assertOk()
        ->assertJsonPath('tag.counter_enabled', true)
        ->assertJsonPath('tag.last_counter', 7);

    expect($tag->fresh()->counter_enabled)->toBeTrue()
        ->and($tag->fresh()->secured_at)->not->toBeNull();
    $this->assertDatabaseHas('nfc_tag_events', ['nfc_tag_id' => $tag->id, 'action' => 'secured']);
});

it('lets the desk secure a tag again to reset a wrong counter', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->secured(900)->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secured", ['counter' => 12])->assertOk();

    expect($tag->fresh()->last_counter)->toBe(12);
});

it('requires a valid counter when marking a tag as secured', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $tag = NfcTag::factory()->create();

    $this->postJson("/api/nfc-tags/{$tag->id}/secured")->assertStatus(422);
    $this->postJson("/api/nfc-tags/{$tag->id}/secured", ['counter' => -5])->assertStatus(422);
});

it('does not let a user outside the scope mark a tag as secured', function () {
    $tag = NfcTag::factory()->create();
    Sanctum::actingAs(User::factory()->association()->create());

    $this->postJson("/api/nfc-tags/{$tag->id}/secured", ['counter' => 5])->assertForbidden();
    expect($tag->fresh()->counter_enabled)->toBeFalse();
});
