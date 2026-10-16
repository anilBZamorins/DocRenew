<?php

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** T25 / FSD 4.3 "Reports" + "Reporting Service" — a requested report generation job. */
class Report extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'requested_by', 'type', 'format', 'status', 'filters',
        'row_count', 'file_key', 'error_message', 'requested_at', 'completed_at',
    ];

    protected $hidden = [
        // Never exposed directly — ReportController::download() issues a
        // short-lived signed URL instead (T21's AttachmentController
        // pattern for 'attachments').
        'file_key',
    ];

    protected $casts = [
        'type' => ReportType::class,
        'format' => ReportFormat::class,
        'status' => ReportStatus::class,
        'filters' => 'array',
        'row_count' => 'integer',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function hasFile(): bool
    {
        return ! empty($this->file_key);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
