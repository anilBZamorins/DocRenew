<?php

namespace App\Console\Commands;

use App\Services\ReminderEvaluator;
use Illuminate\Console\Command;

/**
 * FSD 2.5 primary path: "A background job runs every N minutes (default
 * 15, configurable per tenant)." Registered on the schedule in
 * routes/console.php. Can also be run manually (and is, by
 * ReminderController::scan() for Admin-triggered on-demand scans).
 */
class ScanReminders extends Command
{
    protected $signature = 'reminders:scan';

    protected $description = 'Scan all tenants\' active documents and queue any reminder notifications that are now due.';

    public function handle(ReminderEvaluator $evaluator): int
    {
        $this->info('Scanning documents for due reminders...');

        $totals = $evaluator->evaluateAll();

        $this->info("Scanned {$totals['documents_scanned']} document(s), queued {$totals['notifications_queued']} notification(s).");

        return self::SUCCESS;
    }
}
