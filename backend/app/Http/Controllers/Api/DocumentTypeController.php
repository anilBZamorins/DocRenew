<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * T32 — read-only document-type listing. Every role needs this to
 * label a document and to populate the "type" field when creating or
 * editing one (StoreDocumentRequest/UpdateDocumentRequest both already
 * validate document_type_id against this same table); full CRUD over
 * the catalog itself (add/rename/reorder/retire — FSD 4.5) is T34's
 * admin/config UI, not this one, so this controller deliberately has
 * no store/update/destroy.
 */
class DocumentTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DocumentType::query()->where('tenant_id', $request->user()->tenant_id);

        // Default to active types only (what a create/edit form should
        // offer); retired types can still be requested explicitly so an
        // existing document's type label resolves even after retirement.
        if (! $request->boolean('include_retired')) {
            $query->where('retired', false);
        }

        $types = $query->orderBy('sort_order')->orderBy('name')->get();

        return DocumentTypeResource::collection($types);
    }
}
