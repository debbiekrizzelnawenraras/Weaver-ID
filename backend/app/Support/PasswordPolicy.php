<?php

namespace App\Support;

/**
 * The password rules and the Weak / Medium / Strong level.
 *
 * The browser has a copy of these rules in public/js/password-policy.js so the
 * dashboards can give live feedback. This class is the one that decides: the
 * server checks every password again, and tests/fixtures/password-vectors.json
 * is run against both copies so they cannot drift apart.
 *
 * The level measures rule compliance and length. It is not a guess of how
 * quickly a password can be cracked: "Summer2026!xy" passes every rule.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const STRONG_LENGTH = 16;

    /** Rule ids in the order they are shown to the user. */
    public const ORDER = ['length', 'lower', 'upper', 'number', 'symbol', 'common', 'personal'];

    public const MESSAGES = [
        'length' => 'Use at least 12 characters.',
        'lower' => 'Add a lowercase letter.',
        'upper' => 'Add an uppercase letter.',
        'number' => 'Add a number.',
        'symbol' => 'Add a symbol, such as ! @ # ?',
        'common' => 'Avoid common passwords and patterns such as password, 123456 or aaaa.',
        'personal' => 'Do not include your name or email in the password.',
    ];

    /** Checked against the password with everything but letters and digits removed. */
    private const COMMON = [
        'password', 'passw0rd', 'pssw0rd', 'qwerty', 'asdfgh', 'zxcvbn', '123456', '654321',
        '111111', 'abcdef', 'letmein', 'welcome', 'admin', 'weaverid', 'iloveyou', 'bontoc',
        'mountainprovince',
    ];

    /**
     * The ids of the rules the password does not meet, in display order.
     *
     * @param  array{email?: ?string, name?: ?string}  $context  the account's own details
     * @return list<string>
     */
    public static function failures(string $password, array $context = []): array
    {
        $lower = mb_strtolower($password);
        $plain = self::plain($lower);

        $passed = [
            'length' => mb_strlen($password) >= self::MIN_LENGTH,
            'lower' => preg_match('/\p{Ll}/u', $password) === 1,
            'upper' => preg_match('/\p{Lu}/u', $password) === 1,
            'number' => preg_match('/[0-9]/', $password) === 1,
            'symbol' => preg_match('/[^\p{L}\p{N}\s]/u', $password) === 1,
            'common' => ! self::isCommon($lower, $plain),
            'personal' => ! self::containsPersonalDetail($plain, $context),
        ];

        return array_keys(array_filter($passed, fn (bool $ok) => ! $ok));
    }

    /**
     * @param  array{email?: ?string, name?: ?string}  $context
     * @return 'weak'|'medium'|'strong'
     */
    public static function level(string $password, array $context = []): string
    {
        if (self::failures($password, $context) !== []) {
            return 'weak';
        }

        return mb_strlen($password) >= self::STRONG_LENGTH ? 'strong' : 'medium';
    }

    private static function plain(string $lowercased): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $lowercased) ?? '';
    }

    private static function isCommon(string $lowercased, string $plain): bool
    {
        // The same character four times in a row, such as aaaa or 1111.
        if (preg_match('/(.)\1{3,}/su', $lowercased) === 1) {
            return true;
        }

        foreach (self::COMMON as $word) {
            if (str_contains($plain, $word)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array{email?: ?string, name?: ?string}  $context */
    private static function containsPersonalDetail(string $plain, array $context): bool
    {
        foreach (self::personalTokens($context) as $token) {
            if (str_contains($plain, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Words of four letters or more from the name and the part of the email
     * before the @, lowercased. Shorter ones would block too many passwords.
     *
     * @param  array{email?: ?string, name?: ?string}  $context
     * @return list<string>
     */
    private static function personalTokens(array $context): array
    {
        $tokens = [];

        $email = (string) ($context['email'] ?? '');
        if ($email !== '') {
            $local = mb_strtolower(explode('@', $email)[0]);
            $tokens[] = self::plain($local);
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $local, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $tokens[] = $part;
            }
        }

        $name = (string) ($context['name'] ?? '');
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $tokens[] = $word;
        }

        return array_values(array_filter($tokens, fn (string $t) => mb_strlen($t) >= 4));
    }
}
