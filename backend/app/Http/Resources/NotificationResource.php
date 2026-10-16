<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'stage' => $this->stage,
            'channel' => $this->channel,
            'recipient_role' => $this->recipient_role,
            'recipient_user_id' => $this->recipient_user_id,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            // T24 — the Admin-visible delivery-issues list (FSD 5) needs to
            // see *why* a row failed and, for a row still retrying, when
            // it'll be attempted next.
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at,
            'provider_message_id' => $this->provider_message_id,
            'expiry_date_snapshot' => $this->expiry_date_snapshot?->toDateString(),
            'occurrence_date' => $this->occurrence_date?->toDateString(),
            'queued_at' => $this->queued_at,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'failed_at' => $this->failed_at,
        ];
    }
}
