<?php

namespace App\Services\Channels;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * T24 placeholder — every User has an email (it's the login identifier,
 * NOT NULL in the schema), so this sender never throws PermanentSendException
 * for "no address on file" the way Sms/Push can. T27 swaps this body for a
 * real SendGrid/SES client call; the interface and the dispatcher's
 * retry/backoff logic around it don't change.
 */
class EmailChannelSender implements ChannelSender
{
    public function send(Notification $notification, User $recipient): string
    {
        if (empty($recipient->email)) {
            // Defensive only — email is NOT NULL on users, this should be unreachable.
            throw new PermanentSendException('Recipient has no email address on file.');
        }

        Log::info('[T24 stub] Email dispatch', [
            'notification_id' => $notification->id,
            'to' => $recipient->email,
            'stage' => $notification->stage->value,
        ]);

        // Placeholder provider message id — T27 replaces this with the real
        // provider's returned id (e.g. a SendGrid X-Message-Id).
        return 'stub-email-' . Str::uuid();
    }
}
