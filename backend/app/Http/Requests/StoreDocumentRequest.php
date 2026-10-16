<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FSD 4.1 + db/schema.sql CHECK constraints, reproduced at the
 * application layer so a violation is a clean 422 rather than a DB
 * exception:
 *   - EMPLOYEE_DOCUMENT always requires employee_profile_id.
 *   - EXTENDED_ITEM requires employee_profile_id OR item_label.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Document::class, $this->input('employee_profile_id')]);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'employee_profile_id' => [
                'nullable', 'uuid',
                Rule::exists('employee_profiles', 'id')->where('tenant_id', $tenantId),
            ],
            'document_type_id' => [
                'required', 'uuid',
                Rule::exists('document_types', 'id')->where('tenant_id', $tenantId)->where('retired', false),
            ],
            'category' => ['required', Rule::in(['EMPLOYEE_DOCUMENT', 'EXTENDED_ITEM'])],
            'item_label' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['required', 'date'],
            // Deliberately no 'attachment_key' here — T21 — accepting a
            // raw key from the client would bypass the virus scan and
            // encryption pipeline entirely (and could point at another
            // tenant's object). Attachments are uploaded exclusively via
            // AttachmentController::store(), which sets the key itself.
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $category = $this->input('category');
            $employeeProfileId = $this->input('employee_profile_id');
            $itemLabel = $this->input('item_label');

            if ($category === 'EMPLOYEE_DOCUMENT' && ! $employeeProfileId) {
                $v->errors()->add('employee_profile_id', 'An employee document must have an owning employee.');
            }

            if ($category === 'EXTENDED_ITEM' && ! $employeeProfileId && ! $itemLabel) {
                $v->errors()->add('item_label', 'An extended item with no owning employee must have an item label.');
            }
        });
    }
}
