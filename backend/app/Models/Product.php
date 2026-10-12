<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /** Products of weavers this user may manage. */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('weaver', fn (Builder $q) => $q->manageableBy($user));
    }

    public function weaver(): BelongsTo
    {
        return $this->belongsTo(Weaver::class);
    }

    public function nfcTags(): HasMany
    {
        return $this->hasMany(NfcTag::class);
    }
}