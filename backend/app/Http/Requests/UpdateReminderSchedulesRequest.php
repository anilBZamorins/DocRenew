<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStage;
use App\Models\ReminderSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * T26 / FSD 4.5 "Reminder schedule ... with optional per-document-type
 * overrides" — PATCH /config/reminder-schedules takes a batch of rows
 * (not just one) since an Admin editing the schedule screen naturally
 * changes several stages/overrides at once; each row is upserted by
 * (document_type_id, stage), matching reminder_schedules' own unique
 * index, so there's no separate POST for "create an override" (FSD's
 * endpoint table lists this as GET/PATCH only).
 */
class UpdateReminderSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', ReminderSchedule::class);
    }

    public function rules(): array
    {
        return [
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.document_type_id' => [
                'nullable', 'uuid',
                Rule::exists('document_types', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'schedules.*.stage' => ['required', new Enum(NotificationStage::class)],
            'schedules.*.offset_days' => ['required', 'integer', 'min:0'],
            // Only meaningful for the recurring ESCALATION stage (FSD 5:
            // "repeats on a configurable cadence, default every 7 days");
            // the business-rule cross-check (required for ESCALATION,
            // forbidden otherwise) happens in the controller since it
            // depends on the sibling 'stage' value within the same row.
            'schedules.*.cadence_days' => ['nullable', 'integer', 'min:1'],
            'schedules.*.channels' => ['required', 'array', 'min:1'],
            'schedules.*.channels.*' => [new Enum(NotificationChannel::class)],
            'schedules.*.active' => ['sometimes', 'boolean'],
        ];
    }
}
