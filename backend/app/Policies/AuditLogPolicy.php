<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/** FSD 3.2 permission matrix row "Audit log | Full | View | None | None". */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Hr], true);
    }
}
