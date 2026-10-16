<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\EmployeeProfile;
use App\Models\User;

/**
 * FSD 3.2 permission matrix rows "Employee profiles — all employees" and
 * "Employee profiles — own profile". Visibility (which rows a query
 * returns) is handled separately by EmployeeProfile::visibleTo(); this
 * policy gates individual actions once a specific record is in hand.
 */
class EmployeeProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // every role sees *some* slice — visibleTo() does the scoping.
    }

    public function view(User $user, EmployeeProfile $profile): bool
    {
        if ($profile->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($user->role->isTenantWide()) {
            return true;
        }

        if ($user->role === UserRole::Manager) {
            return $profile->id === $user->employee_profile_id
                || $profile->manager_id === $user->employee_profile_id;
        }

        return $profile->id === $user->employee_profile_id;
    }

    /** FSD 3.2: profile creation is Admin/HR only. */
    public function create(User $user): bool
    {
        return $user->role->isTenantWide();
    }

    /**
     * FSD 3.2/4.4: Admin/HR have full edit; an Employee may edit only
     * their own profile (and only a limited field set, enforced by
     * UpdateEmployeeProfileRequest, not here); Manager has view-only.
     */
    public function update(User $user, EmployeeProfile $profile): bool
    {
        if ($profile->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($user->role->isTenantWide()) {
            return true;
        }

        return $user->role === UserRole::Employee && $profile->id === $user->employee_profile_id;
    }

    /** FSD 4.4: deactivating is Admin/HR only, and never deletes records. */
    public function deactivate(User $user, EmployeeProfile $profile): bool
    {
        return $user->role->isTenantWide() && $profile->tenant_id === $user->tenant_id;
    }
}
