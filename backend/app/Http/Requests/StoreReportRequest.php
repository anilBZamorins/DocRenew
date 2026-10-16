<?php

namespace App\Http\Requests;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** T25 / FSD 4.3 "POST /reports — Request a report generation job". */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Report::class);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(ReportType::class)],
            'format' => ['required', new Enum(ReportFormat::class)],
            'filters' => ['sometimes', 'array'],
            'filters.date_from' => ['sometimes', 'date'],
            'filters.date_to' => ['sometimes', 'date', 'after_or_equal:filters.date_from'],
            'filters.document_type_id' => ['sometimes', 'uuid', 'exists:document_types,id'],
            'filters.department' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
