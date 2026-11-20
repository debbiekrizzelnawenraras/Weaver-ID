<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One delivery of NFC tags received by a Negosyo Center (one municipality's stock). */
class TagBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'municipality',
        'supplier',
        'received_on',
        'notes',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
        ];
    }

    public function tags(): HasMany
    {
        return $this->hasMany(NfcTag::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
