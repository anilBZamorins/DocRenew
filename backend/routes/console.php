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
