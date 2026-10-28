<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A 6-digit code emailed to someone to prove the address is theirs. See App\Support\EmailCodes. */
class EmailCode extends Model
{
    protected $fillable = [
        'email',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'sent_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
