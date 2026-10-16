<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Channels\ChannelSender;
use App\Services\Channels\EmailChannelSender;
use App\Services\Channels\PermanentSendException;
use App\Services\Channels\PushChannelSender;
use App\Services\Channels\SmsChannelSender;
use App\Services\Channels\TransientSendException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * T24 — FSD 5: "Delivery failures retry with exponential backoff (1 min,
 * 5 min, 30 min, 2 hr) up to 4 attempts, after which the notification is
 * marked Failed and surfaced on an Admin-visible delivery-issues list" and
 * "Continue delivering on unaffected channels if one channel's provider is
 * unavailable" — the latter is satisfied simply by each Notification row
 * being per-channel already (T22's queueStage() fans a stage out into one
 * row per channel), so one channel failing never blocks another row's
 * attempt; this class only owns the retry/backoff state machine for a
 * single notification at a time.
 */
class NotificationDispatcher
{
    /**
     * Backoff delay (minutes) indexed by attempt number about to be made:
     * after attempt 1 fails transiently, wait 1 min before attempt 2; after
     * attempt 2, wait 5 min before attempt 3; and so on. FSD 5: "1 min,
     * 5 min, 30 min, 2 hr", capped at 4 attempts total.
     */
    private const BACKOFF_MINUTES = [1, 5, 30, 120];

    private const MAX_ATTEMPTS = 4;

    /**
     * Dispatch a single QUEUED (or retry-due) notification: resolve the
     * right ChannelSender for its channel, attempt the send, and update the
     * row's status/attempt bookkeeping based on the outcome.
     */
    public function dispatch(Notification $notification): void
    {
        $recipient = $notification->recipient;

        // Defensive — shouldn't happen (recipient_user_id is set by
        // ReminderEvaluator::resolveRecipients()/resolveEscalationRecipients()
        // for every row it creates), but a dangling/deleted user shouldn't
        // crash the whole dispatch run or burn a retry slot pointlessly.
        if (! $recipient) {
            $notification->update([
                'status' => NotificationStatus::Failed,
                'failed_at' => now(),
                'last_error' => 'Recipient user no longer exists.',
            ]);

            return;
        }

        $notification->attempt_count += 1;

        try {
            $providerMessageId = $this->resolveSender($notification->channel)->send($notification, $recipient);

            $notification->update([
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
                'provider_message_id' => $providerMessageId,
                'attempt_count' => $notification->attempt_count,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);
        } catch (PermanentSendException $e) {
            // Not worth retrying regardless of how many attempts remain —
            // fail immediately so it surfaces on the delivery-issues list
            // right away instead of waiting out a pointless backoff.
            $notification->update([
                'status' => NotificationStatus::Failed,
                'failed_at' => now(),
                'attempt_count' => $notification->attempt_count,
                'next_attempt_at' => null,
                'last_error' => $e->getMessage(),
            ]);
        } catch (TransientSendException $e) {
            $this->handleTransientFailure($notification, $e);
        } catch (\Throwable $e) {
            // An unexpected error from a sender (or from this method) is
            // treated the same as a transient failure — it might be a bug
            // in a stub/provider client, but failing the whole queue run
            // or silently dropping the notification would be worse than
            // giving it the standard backoff treatment and letting it
            // eventually land on Failed if it keeps happening.
            Log::error('[T24] Unexpected error dispatching notification', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
            $this->handleTransientFailure($notification, $e);
        }
    }

    private function handleTransientFailure(Notification $notification, \Throwable $e): void
    {
        if ($notification->attempt_count >= self::MAX_ATTEMPTS) {
            $notification->update([
                'status' => NotificationStatus::Failed,
                'failed_at' => now(),
                'attempt_count' => $notification->attempt_count,
                'next_attempt_at' => null,
                'last_error' => $e->getMessage(),
            ]);

            return;
        }

        // attempt_count has already been incremented above to the attempt
        // that just failed (1-indexed) — BACKOFF_MINUTES is indexed the
        // same way: after attempt #1 fails, BACKOFF_MINUTES[0] (1 min)
        // elapses before attempt #2 is eligible.
        $delayMinutes = self::BACKOFF_MINUTES[$notification->attempt_count - 1];

        $notification->update([
            'status' => NotificationStatus::Queued,
            'attempt_count' => $notification->attempt_count,
            'next_attempt_at' => Carbon::now()->addMinutes($delayMinutes),
            'last_error' => $e->getMessage(),
        ]);
    }

    private function resolveSender(NotificationChannel $channel): ChannelSender
    {
        return match ($channel) {
            NotificationChannel::Email => new EmailChannelSender(),
            NotificationChannel::Sms => new SmsChannelSender(),
            NotificationChannel::Push => new PushChannelSender(),
        };
    }
}
