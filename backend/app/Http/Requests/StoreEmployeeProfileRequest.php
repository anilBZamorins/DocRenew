<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FSD 4.4 — HR/Admin create employee profiles. */
class StoreEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeProfile::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('employee_profiles', 'employee_code')->where('tenant_id', $tenantId),
            ],
            'department' => ['nullable', 'string', 'max:128'],
            'job_title' => ['nullable', 'string', 'max:128'],
            'manager_id' => [
                'nullable', 'uuid',
                Rule::exists('employee_profiles', 'id')->where('tenant_id', $tenantId),
            ],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
