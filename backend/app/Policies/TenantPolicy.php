<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;

/**
 * T26 / FSD 3.2 "Tenant configuration (reminder rules, doc types,
 * branding) | Full | View | None | None" — same matrix row as
 * ReminderSchedulePolicy, applied to the general org settings
 * (branding, timezone, date format, scan interval/window) instead.
 */
class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $tenant->id === $user->tenant_id
            && in_array($user->role, [UserRole::Admin, UserRole::Hr], true);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $tenant->id === $user->tenant_id && $user->role === UserRole::Admin;
    }
}
