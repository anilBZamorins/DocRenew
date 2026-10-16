<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\ReminderEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * T22 — read-only view onto the scheduling engine's output, scoped the
 * same way document visibility is (FSD 3.2): Admin/HR see every
 * notification in the tenant, everyone else sees only notifications
 * addressed to them.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Notification::query()->where('tenant_id', $user->tenant_id);

        if (! $user->role->isTenantWide()) {
            $query->where('recipient_user_id', $user->id);
        }

        if ($request->filled('document_id')) {
            $query->where('document_id', $request->string('document_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->upper());
        }

        $notifications = $query->orderByDesc('queued_at')->paginate($request->integer('per_page', 25));

        return NotificationResource::collection($notifications);
    }

    /**
     * Admin-only manual trigger — the same engine the scheduled command
     * runs, exposed as an API call for ops/testing (FSD doesn't specify
     * this endpoint explicitly, but a configurable-interval scan job
     * implies an operator needs a way to kick one off on demand).
     */
    public function scan(Request $request, ReminderEvaluator $evaluator): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        $totals = $evaluator->evaluateAll();

        return response()->json($totals);
    }
}
