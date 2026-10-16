<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenewDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
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

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource($document->load('documentType'));
    }

    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        $document->update($request->validated());

        AuditLog::record('document.update', 'Document', $document->id, [
            'fields' => array_keys($request->validated()),
        ]);

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
        ]);

        AuditLog::record('document.renew', 'Document', $document->id, [
            'previous_expiry_date' => $previousExpiry,
            'new_expiry_date' => $document->expiry_date?->toDateString(),
        ]);

        return new DocumentResource($document);
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
