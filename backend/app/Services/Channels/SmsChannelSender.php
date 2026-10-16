<?php

namespace App\Services\Channels;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * T24 placeholder — unlike email, a phone number is NOT guaranteed: it
 * lives only on EmployeeProfile.contact_phone (there is no phone column on
 * User at all — confirmed against the users migration), and only users
 * linked to an employee profile have one. A recipient with no reachable
 * phone number can't be fixed by retrying, so that's PermanentSendException,
 * not TransientSendException. T28 swaps this body for a real SMS gateway
 * client call.
 */
class SmsChannelSender implements ChannelSender
{
    public function send(Notification $notification, User $recipient): string
    {
        $phone = $recipient->employeeProfile?->contact_phone;

        if (empty($phone)) {
            throw new PermanentSendException(
                'Recipient has no phone number on file (no linked employee profile, or contact_phone is empty).'
            );
        }

        Log::info('[T24 stub] SMS dispatch', [
            'notification_id' => $notification->id,
            'to' => $phone,
            'stage' => $notification->stage->value,
        ]);

        // Placeholder provider message id — T28 replaces this with the real
        // SMS gateway's returned id.
        return 'stub-sms-' . Str::uuid();
    }
}
