<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One add, change or remove of a product photo, with the user who did it. */
class ProductPhotoEvent extends Model
{
    protected $fillable = ['product_id', 'user_id', 'action'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->select('id', 'name', 'role');
    }
}
