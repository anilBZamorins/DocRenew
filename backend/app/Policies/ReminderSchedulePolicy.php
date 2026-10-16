<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ReminderSchedule;
use App\Models\User;

/**
 * T26 / FSD 3.2 permission matrix row "Tenant configuration (reminder
 * rules, doc types, branding) | Full | View | None | None": Admin can
 * view and edit; HR can view only; Manager/Employee get no access at
 * all (unlike Compliance reports, there's no "team scope" here — this
 * is tenant-wide config, not per-record data).
 */
class ReminderSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Hr], true);
    }

    public function update(User $user, ?ReminderSchedule $schedule = null): bool
    {
        if ($schedule && $schedule->tenant_id !== $user->tenant_id) {
            return false;
        }

        return $user->role === UserRole::Admin;
    }
}
