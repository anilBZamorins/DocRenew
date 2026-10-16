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
            // NOTE (T33): whenLoaded() has a quirk where, if the relation IS
            // loaded but its value is null, it returns null without ever
            // invoking the mapping closure (see
            // ConditionallyLoadsAttributes::whenLoaded() — it short-circuits
            // on `$loadedValue === null` before calling $value()). That
            // silently turned "no linked user" into a missing/null field
            // instead of `false`. when() doesn't have that shortcut, so it's
            // used here instead, keyed off relationLoaded() directly.
            'has_user_account' => $this->when($this->relationLoaded('user'), fn () => $this->user !== null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
