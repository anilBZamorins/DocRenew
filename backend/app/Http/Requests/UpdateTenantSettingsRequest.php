<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T26 / FSD 4.5 "General settings: branding (logo, name), timezone,
 * date format, default scan interval and scan window." PATCH-style
 * partial update — TenantSettingsController merges whatever's present
 * here into the tenant's existing settings JSON rather than requiring
 * every field on every call.
 */
class UpdateTenantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->tenant);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'date_format' => ['sometimes', 'string', 'in:Y-m-d,d/m/Y,m/d/Y,d-M-Y'],
            // FSD 2.5 "default scan interval (default 15, configurable
            // per tenant)" — stored here for Admin visibility/editing;
            // ScanReminders' actual schedule registration
            // (routes/console.php) is a single system-wide cron entry
            // and does NOT yet read this per-tenant, so changing it
            // here is forward-looking config, not live behavior yet.
            'default_scan_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'default_scan_window_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'default_scan_window_end' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
