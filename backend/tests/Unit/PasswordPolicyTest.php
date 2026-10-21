<?php

use App\Support\PasswordPolicy;

// The same examples are run against the browser copy of the rules by
// tests/js/password-policy.check.cjs, so the two cannot drift apart.
$examples = [];
foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/password-vectors.json'), true) as $example) {
    $examples[$example['note']] = [$example];
}

it('gives the expected failed rules and level for each shared example', function (array $example) {
    $context = ['email' => $example['email'] ?? null, 'name' => $example['name'] ?? null];

    expect(PasswordPolicy::failures($example['password'], $context))->toBe($example['failed'])
        ->and(PasswordPolicy::level($example['password'], $context))->toBe($example['level']);
})->with($examples);

it('has a message for every rule, in display order', function () {
    expect(array_keys(PasswordPolicy::MESSAGES))->toBe(PasswordPolicy::ORDER);
});

it('puts the limits at 12 characters for medium and 16 for strong', function () {
    expect(PasswordPolicy::MIN_LENGTH)->toBe(12)
        ->and(PasswordPolicy::STRONG_LENGTH)->toBe(16);
});
