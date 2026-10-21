<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspiciousReport extends Model
{
    public const STATUSES = ['new', 'under_review', 'verified', 'dismissed', 'referred'];

    protected $fillable = [
        'reference',
        'uid',
        'nfc_tag_id',
        'scan_log_id',
        'flag_reason',
        'reporter_key',
        'app_version',
        'location',
        'seen_on',
        'note',
        'contact',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'seen_on' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Reports about tags this user may manage. Admin sees every report, including
     * those whose tag no longer exists.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        return $query->whereHas('nfcTag', fn (Builder $q) => $q->manageableBy($user));
    }

    public function nfcTag(): BelongsTo
    {
        return $this->belongsTo(NfcTag::class);
    }

    public function scanLog(): BelongsTo
    {
        return $this->belongsTo(ScanLog::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
