<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTenantSettingsRequest;
use App\Http\Resources\TenantSettingsResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * T26 / FSD 4.5 "General settings: branding (logo, name), timezone,
 * date format, default scan interval and scan window." —
 * GET/PATCH /config/settings. Admin/HR per FSD 3.2's "Tenant
 * configuration" row (TenantPolicy) — same matrix row as
 * ReminderScheduleConfigController, just a different slice of config.
 */
class TenantSettingsController extends Controller
{
    public function show(Request $request): TenantSettingsResource
    {
        $tenant = $request->user()->tenant;

        $this->authorize('view', $tenant);

        return new TenantSettingsResource($tenant);
    }

    public function update(UpdateTenantSettingsRequest $request): TenantSettingsResource
    {
        $tenant = $request->user()->tenant;
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $tenant->name = $data['name'];
        }
        if (array_key_exists('timezone', $data)) {
            $tenant->timezone = $data['timezone'];
        }

        // Partial merge, not replace — PATCH semantics: a call that only
        // sends date_format shouldn't wipe out an already-configured
        // logo_url or scan window.
        $settings = $tenant->settings ?? [];
        foreach (['logo_url', 'date_format', 'default_scan_interval_minutes', 'default_scan_window_start', 'default_scan_window_end'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }
        $tenant->settings = $settings;

        $tenant->save();

        AuditLog::record('config.settings.update', 'Tenant', $tenant->id, [
            'fields_changed' => array_keys($data),
        ]);

        return new TenantSettingsResource($tenant);
    }
}
