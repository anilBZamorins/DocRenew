<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'format' => $this->format,
            'status' => $this->status,
            'filters' => $this->filters,
            'row_count' => $this->row_count,
            'error_message' => $this->error_message,
            // Never the raw file_key (Report::$hidden) — a completed
            // report is downloaded via ReportController::show()'s signed
            // URL, same posture as T21's attachments.
            'download_available' => $this->hasFile(),
            'requested_at' => $this->requested_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
