<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FSD 3.2/4.4: Admin/HR may edit every field; an Employee editing their
 * own profile may only touch contact details ("Edit (limited fields)"
 * in the permission matrix). EmployeeProfilePolicy::update() has
 * already ruled out every other case (Manager gets view-only, a
 * mismatched Employee is rejected) by the time rules() runs, so the
 * only two callers who reach here are Admin/HR (full fields) or an
 * Employee on their own profile (contact fields only).
 */
class UpdateEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee_profile'));
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $profileId = $this->route('employee_profile')?->id;

        $full = [
            'name' => ['sometimes', 'string', 'max:255'],
            'employee_code' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('employee_profiles', 'employee_code')->where('tenant_id', $tenantId)->ignore($profileId),
            ],
            'department' => ['sometimes', 'nullable', 'string', 'max:128'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:128'],
            'manager_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('employee_profiles', 'id')->where('tenant_id', $tenantId),
            ],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];

        if ($this->user()->role->isTenantWide()) {
            return $full;
        }

        // Employee editing their own profile: contact details only.
        return array_intersect_key($full, array_flip(['contact_email', 'contact_phone']));
    }
}
