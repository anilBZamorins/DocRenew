<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * T25 / FSD 3.2 "Audit log | Full | View | None | None" — Admin/HR only
 * (ReportPolicy's sibling for the audit trail itself: a Manager or
 * Employee gets no access, full stop, unlike the "team scope" partial
 * access Compliance reports gives a Manager).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('actingUser');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->string('entity_type'));
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->string('entity_id'));
        }
        if ($request->filled('acting_user_id')) {
            $query->where('acting_user_id', $request->string('acting_user_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date('date_to')->endOfDay());
        }

        $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));

        return AuditLogResource::collection($logs);
    }
}
