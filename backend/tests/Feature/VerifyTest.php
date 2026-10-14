<?php

use App\Models\NfcTag;
use App\Models\Product;
use App\Models\Weaver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('authenticates an active registered tag and returns the product profile', function () {
    $tag = NfcTag::factory()->create();
    $product = $tag->product;

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated')
        ->assertJsonPath('product.title', $product->title)
        ->assertJsonPath('association.name', $product->weaver->association->name)
        ->assertJsonPath('association.municipality', $product->weaver->association->municipality)
        ->assertJsonMissingPath('weaver');

    $this->assertDatabaseHas('scan_logs', [
        'uid' => $tag->uid,
        'nfc_tag_id' => $tag->id,
        'result' => 'authenticated',
    ]);
});

it('accepts UIDs with separators and lowercase letters', function () {
    $tag = NfcTag::factory()->create();
    $formatted = implode(':', str_split(strtolower($tag->uid), 2));

    $this->postJson('/api/verify', ['uid' => $formatted])
        ->assertOk()
        ->assertJsonPath('result', 'authenticated');
});

it('does not require authentication', function () {
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])->assertOk();
});

it('returns unregistered for an unknown UID and still logs the scan', function () {
    $this->postJson('/api/verify', ['uid' => '04AABBCCDDEEFF'])
        ->assertOk()
        ->assertJsonPath('result', 'unregistered')
        ->assertJsonMissingPath('product');

    $this->assertDatabaseHas('scan_logs', [
        'uid' => '04AABBCCDDEEFF',
        'nfc_tag_id' => null,
        'result' => 'unregistered',
    ]);
});

it('returns unregistered for a tag that is not linked to a product', function () {
    $tag = NfcTag::factory()->unlinked()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'unregistered');
});

it('flags a revoked tag as suspicious without revealing product details', function () {
    $tag = NfcTag::factory()->revoked()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious')
        ->assertJsonMissingPath('product');
});

it('flags a tag whose product is inactive as suspicious', function () {
    $product = Product::factory()->inactive()->create();
    $tag = NfcTag::factory()->create(['product_id' => $product->id]);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious');
});

it('flags a tag whose weaver is inactive as suspicious', function () {
    $weaver = Weaver::factory()->inactive()->create();
    $product = Product::factory()->create(['weaver_id' => $weaver->id]);
    $tag = NfcTag::factory()->create(['product_id' => $product->id]);

    $this->postJson('/api/verify', ['uid' => $tag->uid])
        ->assertOk()
        ->assertJsonPath('result', 'suspicious');
});

it('flags a tag scanned by too many distinct devices in the window', function () {
    config(['weaver.suspicious_distinct_scanners' => 3]);
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
        ->assertJsonPath('result', 'authenticated');
    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-2'])
        ->assertJsonPath('result', 'authenticated');
    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-3'])
        ->assertJsonPath('result', 'suspicious');
});

it('does not flag repeated scans from the same device', function () {
    config(['weaver.suspicious_distinct_scanners' => 3]);
    $tag = NfcTag::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1'])
            ->assertJsonPath('result', 'authenticated');
    }
});

it('ignores scans outside the time window', function () {
    config([
        'weaver.suspicious_distinct_scanners' => 3,
        'weaver.suspicious_window_minutes' => 30,
    ]);
    $tag = NfcTag::factory()->create();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-1']);
    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-2']);

    $this->travel(31)->minutes();

    $this->postJson('/api/verify', ['uid' => $tag->uid, 'device_id' => 'phone-3'])
        ->assertJsonPath('result', 'authenticated');
});

it('rejects a missing, malformed or non-string UID', function (mixed $uid) {
    $this->postJson('/api/verify', ['uid' => $uid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('uid');

    $this->assertDatabaseCount('scan_logs', 0);
})->with([
    'missing' => [null],
    'not hex' => ['ZZZZZZZZ'],
    'too short' => ['04A1'],
    'too long' => [str_repeat('A', 21)],
    'array' => [['04AABBCCDDEEFF']],
]);

it('rate limits the verify endpoint per IP address', function () {
    foreach (range(1, 30) as $attempt) {
        $this->postJson('/api/verify', ['uid' => '04AABBCCDDEEFF'])->assertOk();
    }

    $this->postJson('/api/verify', ['uid' => '04AABBCCDDEEFF'])->assertStatus(429);
});

it('never exposes member names in the public response', function () {
    $tag = NfcTag::factory()->create();
    $tag->product->weaver->update(['name' => 'Secret Member Name', 'proprietor' => 'Secret Owner']);

    $body = $this->postJson('/api/verify', ['uid' => $tag->uid])->assertOk()->getContent();

    expect($body)->not->toContain('Secret Member Name')->not->toContain('Secret Owner');
});
