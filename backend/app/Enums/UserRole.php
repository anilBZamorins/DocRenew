<?php

namespace App\Enums;

/**
 * FSD 3.1 Role Definitions.
 */
enum UserRole: string
{
    case Admin = 'ADMIN';
    case Hr = 'HR';
    case Manager = 'MANAGER';
    case Employee = 'EMPLOYEE';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Hr => 'HR',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }

    /** Roles with tenant-wide (not team/own-scoped) visibility — FSD 3.2. */
    public function isTenantWide(): bool
    {
        return in_array($this, [self::Admin, self::Hr], true);
    }
}
