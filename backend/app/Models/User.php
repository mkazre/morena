<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Spatie role name => nav-config.js role key / display label. */
    public const ROLE_NAV_KEYS = [
        'admin' => 'admin',
        'branch_manager' => 'manager',
        'hr' => 'hr',
        'agent' => 'agent',
        'landlord' => 'landlord',
        'tenant' => 'tenant',
    ];

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'branch_id', 'status', 'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class, 'tenant_user_id');
    }

    public function primaryRole(): ?string
    {
        return $this->getRoleNames()->first();
    }

    public function navRole(): string
    {
        return self::ROLE_NAV_KEYS[$this->primaryRole()] ?? 'agent';
    }

    public function initials(): string
    {
        return collect(preg_split('/[\s.&]+/', $this->name, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => ctype_upper($w[0] ?? ''))
            ->map(fn ($w) => $w[0])
            ->take(2)
            ->implode('') ?: strtoupper(substr($this->name, 0, 2));
    }
}
