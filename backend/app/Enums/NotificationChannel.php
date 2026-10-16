<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'EMAIL';
    case Sms = 'SMS';
    case Push = 'PUSH';
}
