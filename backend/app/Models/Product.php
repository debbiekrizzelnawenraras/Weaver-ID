<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'weaver_id',
        'title',
        'category',
        'status',
        'photo_path',
    ];

    protected $hidden = ['photo_path'];

    protected $appends = ['photo_url'];

    /** Relative address of the photo, or null when the product has none. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? '/api/product-photos/'.$this->photo_path : null;
    }

    /** Products of weavers this user may manage. */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('weaver', fn (Builder $q) => $q->manageableBy($user));
    }

    public function weaver(): BelongsTo
    {
        return $this->belongsTo(Weaver::class);
    }

    public function photoEvents(): HasMany
    {
        return $this->hasMany(ProductPhotoEvent::class);
    }

    /** The most recent photo event, for the dashboards only (never in the public scan result). */
    public function latestPhotoEvent(): HasOne
    {
        return $this->hasOne(ProductPhotoEvent::class)->latestOfMany();
    }

    public function nfcTags(): HasMany
    {
        return $this->hasMany(NfcTag::class);
    }
}