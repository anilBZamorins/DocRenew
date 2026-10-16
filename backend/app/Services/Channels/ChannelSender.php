<?php

namespace App\Services\Channels;

use App\Models\Notification;
use App\Models\User;

/**
 * T24 — the transport boundary the dispatch service calls through.
 * T27/28/29 swap the stub implementations below for real provider
 * clients (SendGrid/SES, an SMS gateway, FCM/APNS) behind this same
 * interface — NotificationDispatcher's retry/backoff logic doesn't
 * change when that happens.
 */
interface ChannelSender
{
    /**
     * @return string the provider's message id, for provider_message_id
     *
     * @throws TransientSendException worth retrying (FSD 5 backoff)
     * @throws PermanentSendException not worth retrying
     */
    public function send(Notification $notification, User $recipient): string;
}
