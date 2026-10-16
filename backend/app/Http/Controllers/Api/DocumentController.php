<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReassignDocumentOwnerRequest;
use App\Http\Requests\RenewDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\ReminderEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FSD 3.2 / 4.1 — Document CRUD. Visibility mirrors DocumentPolicy's
 * "all employees" vs "own documents" distinction:
 *   Admin/HR   -> tenant-wide
 *   Manager    -> own documents (Full) + direct reports' documents (View)
 *   Employee   -> own documents only
 * Per-record write access is still re-checked by DocumentPolicy on every
 * mutating action, so this scoping only controls what index()/show() can
 * see, never what can be changed.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly ReminderEvaluator $reminderEvaluator,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Document::query()->where('tenant_id', $request->user()->tenant_id);
        $this->scopeVisible($query, $request->user());

        if ($request->filled('employee_profile_id')) {
            $query->where('employee_profile_id', $request->string('employee_profile_id'));
        }

        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->string('document_type_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('expiry_from')) {
            $query->whereDate('expiry_date', '>=', $request->date('expiry_from'));
        }

        if ($request->filled('expiry_to')) {
            $query->whereDate('expiry_date', '<=', $request->date('expiry_to'));
        }

        $documents = $query->with('documentType')->orderBy('expiry_date')
            ->paginate($request->integer('per_page', 25));

        // 'status' is a computed accessor, so filtering by it happens
        // in-memory on the current page rather than at the query layer.
        if ($request->filled('status')) {
            $wanted = $request->string('status')->upper()->toString();
            $documents->setCollection(
                $documents->getCollection()->filter(fn (Document $d) => $d->status === $wanted)->values()
            );
        }

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = Document::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        AuditLog::record('document.create', 'Document', $document->id, [
            'category' => $document->category,
            'employee_profile_id' => $document->employee_profile_id,
        ]);

        // FSD 2.5 event-driven path — don't make a freshly-created
        // already-close-to-expiry document wait for the next 15-min scan.
        $this->reminderEvaluator->evaluateDocument($document);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource($document->load('documentType'));
    }

    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        $validated = $request->validated();

        // T23 — an expiry_date change manually applied outside renew()
        // still starts a fresh cycle: any escalation dismissal recorded
        // against the old expiry no longer applies.
        if (array_key_exists('expiry_date', $validated) && (string) $validated['expiry_date'] !== (string) $document->expiry_date) {
            $validated['escalation_dismissed_at'] = null;
            $validated['escalation_dismissed_reason'] = null;
            $validated['escalation_dismissed_by'] = null;
        }

        $document->update($validated);

        AuditLog::record('document.update', 'Document', $document->id, [
            'fields' => array_keys($request->validated()),
        ]);

        // FSD 2.5 — an expiry_date (or document_type_id) change re-evaluates immediately.
        $this->reminderEvaluator->evaluateDocument($document);

        return new DocumentResource($document);
    }

    /**
     * FSD 4.1: "Marking a document 'Renewed' requires entering a new
     * expiry date... the system records the renewal event and resets the
     * notification schedule against the new expiry date." Resetting the
     * schedule is implicit: status() recomputes from the new expiry_date,
     * and last_renewed_at suppresses re-triggering reminders for 30 days.
     */
    public function renew(RenewDocumentRequest $request, Document $document): DocumentResource
    {
        $previousExpiry = $document->expiry_date?->toDateString();

        $document->update([
            ...$request->validated(),
            'last_renewed_at' => now(),
            // T23 — renewing resolves any in-progress escalation outright.
            'escalation_dismissed_at' => null,
            'escalation_dismissed_reason' => null,
            'escalation_dismissed_by' => null,
        ]);

        AuditLog::record('document.renew', 'Document', $document->id, [
            'previous_expiry_date' => $previousExpiry,
            'new_expiry_date' => $document->expiry_date?->toDateString(),
        ]);

        return new DocumentResource($document);
    }

    /**
     * T23 — FSD "Escalation flow": "Manager/HR resolves by renewing the
     * document or dismissing the escalation with a recorded reason,
     * which is retained in the audit log." This only pauses the
     * recurring ESCALATION stage for the document's current expiry
     * cycle (DocumentPolicy::dismissEscalation) — it does not affect
     * the one-shot stages, which have already fired by the time
     * escalation even starts.
     */
    public function dismissEscalation(Request $request, Document $document): DocumentResource
    {
        $this->authorize('dismissEscalation', $document);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $document->update([
            'escalation_dismissed_at' => now(),
            'escalation_dismissed_reason' => $data['reason'],
            'escalation_dismissed_by' => $request->user()->id,
        ]);

        AuditLog::record('document.escalation.dismissed', 'Document', $document->id, [
            'reason' => $data['reason'],
        ]);

        return new DocumentResource($document);
    }

    /**
     * T31 / FSD 4.3 bulk action "reassign owner (Admin/HR only)" —
     * DocumentPolicy::reassignOwner() restricts this to Admin/HR
     * regardless of who currently owns the document. Reassigning
     * immediately re-evaluates reminders against the new owner, same
     * as update() does for an expiry_date change, so a newly-assigned
     * owner close to expiry doesn't wait for the next 15-min scan.
     */
    public function reassignOwner(ReassignDocumentOwnerRequest $request, Document $document): DocumentResource
    {
        $previousOwnerId = $document->employee_profile_id;
        $newOwnerId = $request->validated('employee_profile_id');

        $document->update(['employee_profile_id' => $newOwnerId]);

        AuditLog::record('document.reassign', 'Document', $document->id, [
            'previous_employee_profile_id' => $previousOwnerId,
            'new_employee_profile_id' => $newOwnerId,
        ]);

        $this->reminderEvaluator->evaluateDocument($document->refresh());

        return new DocumentResource($document->load('documentType'));
    }

    /**
     * T31 / FSD 4.3 bulk action "send a manual reminder" —
     * DocumentPolicy::sendManualReminder() restricts this to Admin/HR.
     * Forces an immediate re-evaluation (so a stage that's due but
     * hasn't been picked up by the 15-min scan yet gets queued right
     * now) and then dispatches, in this same request, every QUEUED
     * notification for this document that's currently eligible to
     * send — not just ones evaluateDocument() just created, since an
     * already-queued-but-not-yet-dispatched row for this document is
     * exactly what "send it now" means too. Mirrors the eligibility
     * check in DispatchNotifications (T24): QUEUED and either never
     * attempted or past its backoff.
     */
    public function sendManualReminder(Request $request, Document $document): JsonResponse
    {
        $this->authorize('sendManualReminder', $document);

        $this->reminderEvaluator->evaluateDocument($document->refresh());

        $eligible = Notification::query()
            ->where('document_id', $document->id)
            ->where('status', 'QUEUED')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->get();

        foreach ($eligible as $notification) {
            $this->notificationDispatcher->dispatch($notification);
        }

        AuditLog::record('document.reminder.manual_send', 'Document', $document->id, [
            'notifications_dispatched' => $eligible->count(),
        ]);

        return response()->json([
            'data' => [
                'notifications_dispatched' => $eligible->count(),
            ],
        ]);
    }

    /**
     * FSD 4.1: document_number is masked everywhere except here, and
     * every reveal is audit-logged.
     */
    public function revealNumber(Request $request, Document $document): JsonResponse
    {
        $this->authorize('revealNumber', $document);

        AuditLog::record('document.viewNumber', 'Document', $document->id, []);

        return response()->json([
            'id' => $document->id,
            'document_number' => $document->document_number,
        ]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        AuditLog::record('document.delete', 'Document', $document->id, [
            'category' => $document->category,
            'employee_profile_id' => $document->employee_profile_id,
        ]);

        $document->delete();

        return response()->json(null, 204);
    }

    private function scopeVisible(Builder $query, User $user): Builder
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
