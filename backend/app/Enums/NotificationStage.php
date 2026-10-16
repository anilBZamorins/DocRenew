<?php

namespace App\Enums;

/** FSD 4.5 / 5 — the four Phase-1 reminder stages (T22's scope; the recurring post-expiry escalation is T23). */
enum NotificationStage: string
{
    case ThreeMonth = 'THREE_MONTH';
    case OneMonth = 'ONE_MONTH';
    case FiveDay = 'FIVE_DAY';
    case OnExpiry = 'ON_EXPIRY';

    /**
     * T23 — FSD "Escalation flow": a 5th stage, fired repeatedly
     * (cadence_days, default 7) once a document is past ON_EXPIRY and
     * still unrenewed, to the employee's Manager rather than the usual
     * Employee/Admin/HR audience. Handled separately from the other
     * four in ReminderEvaluator (it's not a one-shot stage), so it is
     * deliberately excluded from orderedCases() below.
     */
    case Escalation = 'ESCALATION';

    /** Default offset in days before expiry (0 = on the expiry date itself) — FSD 4.5's "90d / 30d / 5d / 0d". */
    public function defaultOffsetDays(): int
    {
        return match ($this) {
            self::ThreeMonth => 90,
            self::OneMonth => 30,
            self::FiveDay => 5,
            self::OnExpiry, self::Escalation => 0,
        };
    }

    /** The four one-shot stages, in firing order, earliest (furthest from expiry) first. Escalation is handled separately. */
    public static function orderedCases(): array
    {
        return [self::ThreeMonth, self::OneMonth, self::FiveDay, self::OnExpiry];
    }
}
