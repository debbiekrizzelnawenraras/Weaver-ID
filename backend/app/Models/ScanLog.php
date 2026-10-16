<?php

namespace App\Models;

use App\Enums\ScanResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanLog extends Model
{
    protected $fillable = [
        'uid',
        'nfc_tag_id',
        'result',
        'device_id',
        'ip_address',
        'counter',
        'flag_reason',
    ];

    protected function casts(): array
    {
        return [
            'result' => ScanResult::class,
        ];
    }

    public function nfcTag(): BelongsTo
    {
        return $this->belongsTo(NfcTag::class);
    }
}
