<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// T22 / FSD 2.5: default scan interval is 15 minutes; a tenant-configurable
// interval is out of scope for this task (that lives in Configuration
// Management, FSD 4.5) — ->withoutOverlapping() guards against a scan
// still running past the next tick.
Schedule::command('reminders:scan')->everyFifteenMinutes()->withoutOverlapping();

// T24 / FSD 5: dispatch runs far more often than the scan — it's the layer
// that actually sends QUEUED rows (including ones waiting out a backoff
// delay) and needs to notice a due retry quickly, not just once every
// 15 minutes.
Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping();
