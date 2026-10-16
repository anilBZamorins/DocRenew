<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReminderSchedulesRequest;
use App\Http\Resources\ReminderScheduleResource;
use App\Models\AuditLog;
use App\Models\ReminderSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * T26 / FSD 4.5 "Reminder schedule: tenant-wide default stages ... with
 * optional per-document-type overrides" — GET/PATCH /config/reminder-schedules.
 * Admin/HR per FSD 3.2's "Tenant configuration" row (ReminderSchedulePolicy).
 */
class ReminderScheduleConfigController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReminderSchedule::class);

        $schedules = ReminderSchedule::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('documentType')
            ->orderBy('document_type_id')
            ->orderBy('stage')
            ->get();

        return ReminderScheduleResource::collection($schedules);
    }

    /**
     * Upserts a batch of rows keyed by (document_type_id, stage) — the
     * same composite the table's unique index enforces — so this one
     * endpoint both edits an existing stage's offset/channels AND
     * creates a new per-document-type override, matching FSD's
     * GET/PATCH-only endpoint table (no separate POST).
     */
    public function update(UpdateReminderSchedulesRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $updated = [];

        foreach ($request->validated('schedules') as $row) {
            $stage = $row['stage'];
            $isEscalation = $stage === 'ESCALATION';

            // Business rule that can't be expressed as a single-field
            // validation rule: cadence_days only makes sense for the
            // recurring ESCALATION stage (FSD 5) — the other four are
            // one-shot per cycle (T22/T23's NotificationStage::orderedCases()
            // deliberately excludes Escalation for the same reason).
            if (! $isEscalation && array_key_exists('cadence_days', $row) && $row['cadence_days'] !== null) {
                return response()->json([
                    'message' => 'cadence_days is only applicable to the ESCALATION stage.',
                    'errors' => ['schedules' => ["cadence_days is not applicable to stage {$stage}."]],
                ], 422);
            }
            if ($isEscalation && empty($row['cadence_days'])) {
                return response()->json([
                    'message' => 'cadence_days is required for the ESCALATION stage.',
                    'errors' => ['schedules' => ['cadence_days is required for stage ESCALATION.']],
                ], 422);
            }

            $schedule = ReminderSchedule::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'document_type_id' => $row['document_type_id'] ?? null,
                    'stage' => $stage,
                ],
                [
                    'offset_days' => $row['offset_days'],
                    'cadence_days' => $isEscalation ? $row['cadence_days'] : null,
                    'channels' => $row['channels'],
                    'active' => $row['active'] ?? true,
                ]
            );

            $updated[] = $schedule;
        }

        AuditLog::record('config.reminder_schedules.update', 'Tenant', $tenantId, [
            'rows_updated' => count($updated),
        ]);

        // $updated is a plain array of model instances (not all from the
        // same query), so each resource lazy-loads its own documentType
        // relation rather than batching an eager load here — fine for
        // the small, admin-driven batch sizes this endpoint sees.
        return ReminderScheduleResource::collection($updated);
    }
}
