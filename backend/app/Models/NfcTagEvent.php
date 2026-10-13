<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcTagEvent extends Model
{
    protected $fillable = [
        'nfc_tag_id',
        'user_id',
        'action',
        'from_product_id',
        'to_product_id',
    ];

    public function nfcTag(): BelongsTo
    {
        return $this->belongsTo(NfcTag::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
