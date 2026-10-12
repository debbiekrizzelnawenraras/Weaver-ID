<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'weaver_id',
        'title',
        'category',
        'status',
    ];

    public function weaver(): BelongsTo
    {
        return $this->belongsTo(Weaver::class);
    }

    public function nfcTags(): HasMany
    {
        return $this->hasMany(NfcTag::class);
    }
}