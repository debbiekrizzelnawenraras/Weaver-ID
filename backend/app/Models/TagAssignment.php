<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a delivery list: the administrator assigned this UID to this Negosyo Center.
 * received_at is set when that center receives the tag into its stock.
 */
class TagAssignment extends Model
{
    protected $fillable = ['uid', 'municipality', 'delivery_ref', 'assigned_by', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
