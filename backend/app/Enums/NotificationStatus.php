<?php

namespace App\Enums;

/** FSD 5: "status (Queued/Sent/Delivered/Failed/Bounced)". T22 only ever creates Queued rows. */
enum NotificationStatus: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Bounced = 'BOUNCED';
}
