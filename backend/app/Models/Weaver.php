<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Weaver extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'name',
        'proprietor',
        'municipality',
        'status',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}