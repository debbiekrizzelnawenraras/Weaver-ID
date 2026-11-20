<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Negosyo Center tried to receive a tag that the administrator assigned to another center.
 * The receive was refused; the alert stays open until the admin moves or dismisses it.
 */
class TagAlert extends Model
{
    public const STATUSES = ['open', 'moved', 'dismissed'];

    protected $fillable = ['uid', 'assigned_municipality', 'attempted_municipality', 'attempted_by', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function attempter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
