<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NfcTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid',
        'tag_batch_id',
        'association_id',
        'sent_by',
        'sent_at',
        'product_id',
        'status',
        'counter_enabled',
        'code_enabled',
        'last_counter',
        'secured_at',
        'issued_by',
        'issued_at',
    ];

    /** Inventory states, in the order a tag normally moves through them. */
    public const STATUSES = ['in_stock', 'sent', 'active', 'revoked', 'damaged'];

    /**
     * Tags this user may see. Admin: all. Staff: their own Negosyo Center's stock (tags of
     * batches received in their municipality, not sent yet) plus tags sent to associations
     * in their municipality. Association: only tags sent to it.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'admin' => $query,
            'staff' => filled($user->municipality)
                ? $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $stock) => $stock
                        ->whereNull('association_id')
                        ->whereHas('batch', fn (Builder $b) => $b->where('municipality', $user->municipality)))
                    ->orWhereHas('association', fn (Builder $a) => $a->manageableBy($user)))
                : $query->whereRaw('1 = 0'),
            'association' => $user->association_id
                ? $query->where('association_id', $user->association_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
            'secured_at' => 'datetime',
            'counter_enabled' => 'boolean',
            'code_enabled' => 'boolean',
        ];
    }

    /**
     * Normalize a UID as reported by readers and phones: "04:a1:b2 c3" becomes "04A1B2C3".
     */
    public static function normalizeUid(string $uid): string
    {
        return strtoupper(preg_replace('/[\s:\-]/', '', $uid));
    }

    public function events(): HasMany
    {
        return $this->hasMany(NfcTagEvent::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TagBatch::class, 'tag_batch_id');
    }

    /** The association the tag was sent to. */
    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }
}
