<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FSD 4.1: "Marking a document 'Renewed' requires entering a new expiry
 * date (and optionally a new document number/attachment)."
 */
class RenewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('renew', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'expiry_date' => ['required', 'date', 'after:today'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            // No 'attachment_key' — see StoreDocumentRequest's comment.
            // A renewed attachment is uploaded separately via
            // AttachmentController::store() after this call.
        ];
    }
}
