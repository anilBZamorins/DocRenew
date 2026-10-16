<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'employee_code' => $this->employee_code,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'manager_id' => $this->manager_id,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'active' => $this->active,
            'has_user_account' => $this->whenLoaded('user', fn () => $this->user !== null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
