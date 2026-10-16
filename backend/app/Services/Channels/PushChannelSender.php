<?php

namespace App\Services\Channels;

use App\Models\Notification;
use App\Models\User;

/**
 * T24 placeholder — there is no device-token storage anywhere in the schema
 * yet (that's T29's job to add, alongside the real FCM/APNS client). Every
 * push send is therefore unconfigurable right now, which is a permanent
 * failure — retrying can't fix a missing device token any faster than
 * waiting for T29 to ship. Using PermanentSendException (rather than
 * silently skipping, or treating it as transient and burning the retry
 * budget) keeps this honest: a push-channel notification lands on the
 * Admin-visible delivery-issues list with a clear reason instead of quietly
 * vanishing or retrying four times for no reason.
 */
class PushChannelSender implements ChannelSender
{
    public function send(Notification $notification, User $recipient): string
    {
        throw new PermanentSendException(
            'Push notifications are not configured yet (no device-token storage — see T29).'
        );
    }
}
