<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T31 — Live compliance dashboard UI needs somewhere to get its five
 * summary tiles from (FSD 4.3: "Summary tiles: counts of Valid,
 * Expiring Soon (<=90d), Expiring Urgent (<=5d), Expired, and Renewed
 * (last 30d) documents in scope"). No earlier backend task built this
 * (T20's DocumentController::index() is paginated, so it can't give an
 * accurate tenant-wide count), so it's added here alongside the UI that
 * actually needs it. Scoping mirrors DocumentController::scopeVisible()
 * exactly — Admin/HR tenant-wide, Manager own + direct reports, Employee
 * own only (FSD 3.2 "Live dashboard" row).
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Document::query()->where('tenant_id', $user->tenant_id);
        $this->scopeVisible($query, $user);

        // 'status' is a computed accessor (today vs expiry_date in the
        // tenant's timezone), same reason DocumentController::index()
        // filters status in-memory — there's no getting around loading
        // the rows once. FSD's own performance target (<=10,000
        // documents per tenant, <2s) is small enough that this is fine
        // as a single query + in-memory tally rather than needing its
        // own cached/materialized counter table.
        $counts = ['VALID' => 0, 'EXPIRING_SOON' => 0, 'EXPIRING_URGENT' => 0, 'EXPIRED' => 0, 'RENEWED' => 0];

        foreach ($query->get() as $document) {
            $counts[$document->status] = ($counts[$document->status] ?? 0) + 1;
        }

        return response()->json([
            'data' => [
                'valid' => $counts['VALID'],
                'expiring_soon' => $counts['EXPIRING_SOON'],
                'expiring_urgent' => $counts['EXPIRING_URGENT'],
                'expired' => $counts['EXPIRED'],
                'renewed_last_30d' => $counts['RENEWED'],
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Mirrors DocumentController::scopeVisible() exactly (FSD 3.2). */
    private function scopeVisible(Builder $query, $user): Builder
    {
        if ($user->role->isTenantWide()) {
            return $query;
        }

        if ($user->role === UserRole::Manager && $user->employee_profile_id) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('employee_profile_id', $user->employee_profile_id)
                  ->orWhereIn('employee_profile_id', function ($sub) use ($user) {
                      $sub->select('id')->from('employee_profiles')->where('manager_id', $user->employee_profile_id);
                  });
            });
        }

        return $query->where('employee_profile_id', $user->employee_profile_id);
    }
}
