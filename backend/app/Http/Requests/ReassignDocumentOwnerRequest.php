<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T31 / FSD 4.3 bulk action "reassign owner (Admin/HR only)" —
 * DocumentPolicy::reassignOwner() does the role check; this just
 * validates the target employee_profile_id is a real, active profile
 * in the acting user's own tenant (never a cross-tenant id, never a
 * deactivated employee).
 */
class ReassignDocumentOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reassignOwner', $this->route('document'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'employee_profile_id' => [
                'required', 'uuid',
                Rule::exists('employee_profiles', 'id')->where('tenant_id', $tenantId)->where('active', true),
            ],
        ];
    }
}
