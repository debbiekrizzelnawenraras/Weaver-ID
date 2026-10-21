<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AssociationApplication extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'reference',
        'association_name',
        'municipality',
        'contact_name',
        'contact_phone',
        'email',
        'password_hash',
        'registration_details',
        'status',
        'consented_at',
        'notice_version',
        'decision_note',
        'decided_by',
        'decided_at',
        'association_id',
        'user_id',
    ];

    /** The applicant's password hash never leaves the server, not even to administrators. */
    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Applications this user may decide: admin all, staff those for their own municipality
     * (compared without regard to capitals). Anyone else none.
     */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }

        if ($user->role === 'staff' && filled($user->municipality)) {
            return $query->whereRaw('LOWER(municipality) = ?', [mb_strtolower(trim($user->municipality))]);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** True when a pending application already uses this association name (ignoring case, spaces, punctuation). */
    public static function pendingNameTaken(string $name): bool
    {
        $key = Association::nameKey($name);

        return $key !== '' && self::pending()->pluck('association_name')
            ->contains(fn (string $existing) => Association::nameKey($existing) === $key);
    }

    /** True when a pending application already uses this email address. */
    public static function pendingEmailTaken(string $email): bool
    {
        return self::pending()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->exists();
    }

    /** True when an account already signs in with this email address. */
    public static function accountEmailTaken(string $email): bool
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->exists();
    }

    public static function newReference(): string
    {
        do {
            $reference = 'AP-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
