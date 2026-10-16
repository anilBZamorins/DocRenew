<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FSD 4.1 / 10: document_number is never exposed in full here — only the
 * masked form. The full value is returned exclusively by
 * DocumentController::revealNumber(), which also writes the audit log
 * entry FSD 4.1 requires. Likewise the raw attachment storage key is
 * never exposed (FSD 10 "object-store files are never served
 * directly") — only safe metadata, with the actual signed download URL
 * issued on demand by AttachmentController::show().
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_profile_id' => $this->employee_profile_id,
            'document_type_id' => $this->document_type_id,
            'document_type' => $this->whenLoaded('documentType', fn () => $this->documentType->name),
            'category' => $this->category,
            'item_label' => $this->item_label,
            'document_number_masked' => $this->document_number_masked,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'attachment' => $this->hasAttachment() ? [
                'file_name' => $this->attachment_original_name,
                'mime_type' => $this->attachment_mime_type,
                'size_bytes' => $this->attachment_size_bytes,
                'scanned_at' => $this->attachment_scanned_at,
            ] : null,
            'status' => $this->status,
            'last_renewed_at' => $this->last_renewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
