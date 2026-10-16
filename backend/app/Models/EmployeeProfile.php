<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'employee_code', 'department', 'job_title',
        'manager_id', 'contact_email', 'contact_phone', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(EmployeeProfile::class, 'manager_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'employee_profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * FSD 3.2 / 3.3 — scope a query to the employee profiles visible to
     * the given authenticated user, based on role:
     *   Admin/HR   -> tenant-wide (no extra scoping beyond tenant_id)
     *   Manager    -> own profile + direct reports (manager_id = own profile id)
     *   Employee   -> own profile only
     *
     * Multi-level hierarchies (a Manager's Manager) are explicitly out of
     * scope for Phase 1 per FSD 3.3 — direct reports only.
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        $query->where('tenant_id', $user->tenant_id);

        if ($user->role->isTenantWide()) {
            return $query;
        }

        if ($user->role === UserRole::Manager && $user->employee_profile_id) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('id', $user->employee_profile_id)
                  ->orWhere('manager_id', $user->employee_profile_id);
            });
        }

        // Employee — own profile only.
        return $query->where('id', $user->employee_profile_id);
    }
}
