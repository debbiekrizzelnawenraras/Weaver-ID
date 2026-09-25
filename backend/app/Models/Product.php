<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}