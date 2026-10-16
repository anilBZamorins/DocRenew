<?php

namespace App\Models;

use App\Enums\NotificationStage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'document_type_id', 'stage', 'offset_days', 'cadence_days', 'channels', 'active',
    ];

    protected $casts = [
        'stage' => NotificationStage::class,
        'channels' => 'array',
        'active' => 'boolean',
        'offset_days' => 'integer',
        'cadence_days' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * Resolves the effective schedule row for a given tenant/stage,
     * preferring a document-type-specific override over the tenant-wide
     * default (FSD 4.5). Returns null if neither exists or the matching
     * row is inactive.
     */
    public static function resolve(string $tenantId, ?string $documentTypeId, NotificationStage $stage): ?self
    {
        $query = static::query()->where('tenant_id', $tenantId)->where('stage', $stage->value)->where('active', true);

        if ($documentTypeId) {
            $override = (clone $query)->where('document_type_id', $documentTypeId)->first();
            if ($override) {
                return $override;
            }
        }

        return $query->whereNull('document_type_id')->first();
    }
}
