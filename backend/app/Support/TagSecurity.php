<?php

namespace App\Support;

use RuntimeException;

/**
 * Write password for NTAG215 tags.
 *
 * The password is not stored anywhere. It is derived from the tag's UID and a
 * secret kept on the server (WEAVER_TAG_KEY), so the same UID always gives the
 * same password and the tag desk can ask for it when it needs to write.
 * It protects the tag against being edited. It does not stop a tag from being
 * read or copied.
 */
class TagSecurity
{
    public const MAX_COUNTER = 16777215; // 24-bit NFC counter

    /**
     * @return array{password: string, pack: string} password = 4 bytes, pack = 2 bytes, as hex.
     */
    public static function credentials(string $uid): array
    {
        $key = config('weaver.tag_key');

        if (! is_string($key) || strlen($key) < 16) {
            throw new RuntimeException('WEAVER_TAG_KEY is not set or is too short.');
        }

        $raw = hash_hmac('sha256', 'weaver-id/tag-lock/v1|'.strtoupper($uid), $key, true);

        return [
            'password' => strtoupper(bin2hex(substr($raw, 0, 4))),
            'pack' => strtoupper(bin2hex(substr($raw, 4, 2))),
        ];
    }

    public static function keyConfigured(): bool
    {
        $key = config('weaver.tag_key');

        return is_string($key) && strlen($key) >= 16;
    }
}
