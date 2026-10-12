<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NfcTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid',
        'product_id',
        'status',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Normalize a UID as reported by readers and phones: "04:a1:b2 c3" becomes "04A1B2C3".
     */
    public static function normalizeUid(string $uid): string
    {
        return strtoupper(preg_replace('/[\s:\-]/', '', $uid));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
