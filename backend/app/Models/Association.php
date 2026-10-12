<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Association extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'municipality',
        'status',
    ];

    /**
     * Associations this user may manage: admin all, staff those in their
     * own municipality, association users only their own.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'admin' => $query,
            'staff' => filled($user->municipality)
                ? $query->where('municipality', $user->municipality)
                : $query->whereRaw('1 = 0'),
            'association' => $user->association_id
                ? $query->where('id', $user->association_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function weavers(): HasMany
    {
        return $this->hasMany(Weaver::class);
    }
}