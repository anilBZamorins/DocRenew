<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'logo_url' => $settings['logo_url'] ?? null,
            'date_format' => $settings['date_format'] ?? 'Y-m-d',
            'default_scan_interval_minutes' => $settings['default_scan_interval_minutes'] ?? 15,
            'default_scan_window_start' => $settings['default_scan_window_start'] ?? null,
            'default_scan_window_end' => $settings['default_scan_window_end'] ?? null,
        ];
    }
}
