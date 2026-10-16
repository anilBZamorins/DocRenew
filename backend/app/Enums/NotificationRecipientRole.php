<?php

namespace App\Enums;

/** Why a given user received this notification — kept distinct from the user's current UserRole, since it's a point-in-time record. */
enum NotificationRecipientRole: string
{
    case Employee = 'EMPLOYEE';
    case Admin = 'ADMIN';
    case Hr = 'HR';
    case Manager = 'MANAGER';
}
