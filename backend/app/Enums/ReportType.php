<?php

namespace App\Enums;

/** FSD 4.3 "Reports (generated on demand, PDF or CSV)". */
enum ReportType: string
{
    case ComplianceSnapshot = 'COMPLIANCE_SNAPSHOT';
    case Overdue = 'OVERDUE';
    case NotificationActivity = 'NOTIFICATION_ACTIVITY';
    case RenewalHistory = 'RENEWAL_HISTORY';

    public function label(): string
    {
        return match ($this) {
            self::ComplianceSnapshot => 'Compliance Snapshot',
            self::Overdue => 'Overdue / Expired',
            self::NotificationActivity => 'Notification Activity',
            self::RenewalHistory => 'Renewal History',
        };
    }
}
