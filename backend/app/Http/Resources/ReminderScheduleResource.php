<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type_id' => $this->document_type_id,
            'document_type_name' => $this->documentType?->name, // null => the tenant-wide default row
            'stage' => $this->stage,
            'offset_days' => $this->offset_days,
            'cadence_days' => $this->cadence_days,
            'channels' => $this->channels,
            'active' => $this->active,
            'updated_at' => $this->updated_at,
        ];
    }
}
