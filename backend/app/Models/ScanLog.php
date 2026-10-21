<?php

namespace App\Models;

use App\Enums\ScanResult;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scans this user may see: admin all, association users the scans of their own
     * association's tags. Staff have no scan log view, so they get none.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'admin' => $query,
            'association' => $query->whereHas('nfcTag', fn (Builder $q) => $q->manageableBy($user)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function nfcTag(): BelongsTo
    {
        return $this->belongsTo(NfcTag::class);
    }
}
