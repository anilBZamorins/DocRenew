<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;

/**
 * FSD 3.2 permission matrix row "Compliance reports | Full | Full |
 * Team scope | None": Admin/HR can request and view any report in the
 * tenant; a Manager may request and view only reports THEY requested
 * (the "team scope" restriction on the report's CONTENTS is enforced by
 * ReportDataBuilder, not here — a Manager never sees another Manager's
 * report even though both are nominally "team scope"); an Employee gets
 * no access at all.
 */
class ReportPolicy
{
    public function create(User $user): bool
    {
        return $user->role !== UserRole::Employee;
    }

    public function view(User $user, Report $report): bool
    {
        if ($report->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($user->role->isTenantWide()) {
            return true;
        }

        if ($user->role === UserRole::Manager) {
            return $report->requested_by === $user->id;
        }

        return false;
    }

    public function download(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }
}
