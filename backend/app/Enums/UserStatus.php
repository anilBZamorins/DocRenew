<?php

namespace App\Enums;

/**
 * FSD 3.4 Account lifecycle: Invited → Active → Suspended.
 */
enum UserStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
