<?php

namespace App\Enums;

enum ScanResult: string
{
    case Authenticated = 'authenticated';
    case Unregistered = 'unregistered';
    case Suspicious = 'suspicious';

    public function message(): string
    {
        return match ($this) {
            self::Authenticated => 'This product is registered and authenticated by DTI-Mountain Province.',
            self::Unregistered => 'This tag is not registered in the Weaver-ID database.',
            self::Suspicious => 'This tag has been flagged. Do not rely on it as proof of authenticity and report it to DTI-Mountain Province.',
        };
    }
}
