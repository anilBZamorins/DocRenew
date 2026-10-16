<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'document_type_id' => [
                'sometimes', 'uuid',
                Rule::exists('document_types', 'id')->where('tenant_id', $tenantId)->where('retired', false),
            ],
            'item_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issue_date' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'date'],
            // No 'attachment_key' — see StoreDocumentRequest's comment;
            // attachments are only ever set via AttachmentController.
        ];
    }
}
