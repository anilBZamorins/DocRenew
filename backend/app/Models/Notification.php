<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationRecipientRole;
use App\Enums\NotificationStage;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FSD 5 / 6.2 — the delivery log. T22 only writes QUEUED rows; T24/27-29 own the rest of the lifecycle. */
class Notification extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'document_id', 'stage', 'channel', 'recipient_role', 'recipient_user_id',
        'status', 'provider_message_id', 'attempt_count', 'expiry_date_snapshot', 'occurrence_date',
        'queued_at', 'sent_at', 'delivered_at', 'failed_at',
        // T24
        'next_attempt_at', 'last_error',
    ];

    protected $casts = [
        'stage' => NotificationStage::class,
        'channel' => NotificationChannel::class,
        'recipient_role' => NotificationRecipientRole::class,
        'status' => NotificationStatus::class,
        'expiry_date_snapshot' => 'date',
        'occurrence_date' => 'date',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
