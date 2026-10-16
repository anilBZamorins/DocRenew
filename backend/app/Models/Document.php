<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'employee_profile_id', 'document_type_id', 'category',
        'item_label', 'document_number', 'issue_date', 'expiry_date',
        'attachment_key', 'attachment_original_name', 'attachment_mime_type',
        'attachment_size_bytes', 'attachment_scanned_at', 'last_renewed_at',
        'escalation_dismissed_at', 'escalation_dismissed_reason', 'escalation_dismissed_by',
    ];

    protected $hidden = [
        // document_number_enc: the raw ciphertext column, never the API
        // concern — the number itself is already masked by default
        // (document_number_masked) and only unmasked by revealNumber().
        // attachment_key: the raw storage path/object key. T21 — per
        // FSD 10 "object-store files are never served directly," the
        // key must never leave the server; AttachmentController issues a
        // short-lived signed URL instead of exposing it.
        'document_number_enc', 'attachment_key',
    ];

    protected $appends = [
        'status', 'document_number_masked',
    ];

    protected $casts = [
        // Laravel's native 'encrypted' cast (AES-256-CBC via APP_KEY) —
        // db/schema.sql's recommended approach for document_number_enc.
        'document_number_enc' => 'encrypted',
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'last_renewed_at' => 'datetime',
        'attachment_scanned_at' => 'datetime',
        'attachment_size_bytes' => 'integer',
        'escalation_dismissed_at' => 'datetime',
    ];

    public function hasAttachment(): bool
    {
        return ! empty($this->attachment_key);
    }

    /** T23 — true while a dismissal is in effect for the document's CURRENT expiry cycle. */
    public function hasActiveEscalationDismissal(): bool
    {
        if (! $this->escalation_dismissed_at) {
            return false;
        }

        // A renewal (or any expiry_date change) after the dismissal starts a new cycle.
        return ! $this->last_renewed_at || $this->escalation_dismissed_at->gte($this->last_renewed_at);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** Convenience alias so controllers/requests can use 'document_number' rather than the _enc column name. */
    public function getDocumentNumberAttribute(): ?string
    {
        return $this->document_number_enc;
    }

    public function setDocumentNumberAttribute(?string $value): void
    {
        $this->document_number_enc = $value;
    }

    /**
     * FSD 4.1: "masked in list views, shown in full only on explicit
     * reveal with an audit entry." DocumentPolicy::revealNumber() plus
     * DocumentController::revealNumber() is what actually returns the
     * full value (and writes the audit_logs row) — this accessor is the
     * safe default that every other response path gets.
     */
    protected function documentNumberMasked(): Attribute
    {
        return Attribute::make(get: function () {
            $value = $this->document_number_enc;

            if (! $value) {
                return null;
            }

            $tail = substr($value, -4);

            return str_repeat('*', max(strlen($value) - 4, 4)).$tail;
        });
    }

    /**
     * Mirrors db/schema.sql's commented-out status logic (FSD 4.1):
     * recomputed on every read, never stored.
     */
    protected function status(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->last_renewed_at && $this->last_renewed_at->gt(now()->subDays(30))) {
                return 'RENEWED';
            }

            $timezone = $this->tenant?->timezone ?? 'UTC';
            $days = now($timezone)->startOfDay()->diffInDays($this->expiry_date, false);

            return match (true) {
                $days < 0 => 'EXPIRED',
                $days <= 5 => 'EXPIRING_URGENT',
                $days <= 90 => 'EXPIRING_SOON',
                default => 'VALID',
            };
        });
    }
}
