<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'tenant_id', 'email', 'role', 'employee_profile_id', 'status',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        // Laravel's 'hashed' cast bcrypt-hashes on assignment (config/hashing.php
        // sets bcrypt rounds to 12 — FSD 3.4 password policy).
        'password_hash' => 'hashed',
        'role' => UserRole::class,
        'status' => UserStatus::class,
        'last_login_at' => 'datetime',
    ];

    /**
     * users.password_hash is the schema.sql column name; map it to
     * Laravel's expected auth password so the framework's own guards,
     * password broker, etc. work unmodified.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Allows User::create(['password' => ...]) / $user->password = ... as a
     * convenience alias for password_hash. Goes through the normal
     * attribute setter (not a direct $this->attributes write) so the
     * 'hashed' cast on password_hash still applies.
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->password_hash = $value;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
