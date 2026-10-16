<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * T24 / FSD 5: the dispatch half of the pipeline — T22's reminders:scan
 * only ever creates QUEUED rows; this command is what actually attempts to
 * send them, with the exponential-backoff retry handled by
 * NotificationDispatcher. Registered on the schedule in routes/console.php.
 */
class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Attempt delivery of QUEUED notifications that are due (or due for retry), with exponential backoff.';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $due = Notification::where('status', NotificationStatus::Queued)
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->get();

        $this->info("Found {$due->count()} notification(s) due for dispatch.");

        $sent = 0;
        $failed = 0;
        $retrying = 0;

        foreach ($due as $notification) {
            $dispatcher->dispatch($notification);

            match ($notification->refresh()->status) {
                NotificationStatus::Sent => $sent++,
                NotificationStatus::Failed => $failed++,
                NotificationStatus::Queued => $retrying++,
                default => null,
            };
        }

        $this->info("Dispatched: {$sent} sent, {$failed} failed (exhausted retries or permanent), {$retrying} re-queued for retry.");

        return self::SUCCESS;
    }
}
