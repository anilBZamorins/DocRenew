<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationRecipientRole;
use App\Enums\NotificationStage;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Notification;
use App\Models\ReminderSchedule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * T22 — the reminder scheduling engine (FSD 2.5 / 4.5 / 5).
 *
 * This is deliberately just the MATCHING + QUEUEING half of the
 * notification pipeline: for a document, it decides which stages
 * (3mo/1mo/5-day/on-expiry) are currently due and, for each one not
 * already queued for the document's current expiry date, writes one
 * QUEUED Notification row per configured channel per recipient. It
 * does not render templates, call a provider, or retry a failed send —
 * that's the dispatch service (T24) and the channel adapters (T27-29).
 *
 * T23 adds the ESCALATION stage: FSD "Escalation flow" — once a document
 * is past ON_EXPIRY and still unrenewed, it fires repeatedly (every
 * cadence_days, default 7) to the employee's Manager, until the document
 * is renewed or the Manager/HR dismisses it with a reason
 * (DocumentController::dismissEscalation). Unlike the other four stages
 * it is not one-shot, so it's evaluated and deduped separately below.
 */
class ReminderEvaluator
{
    /**
     * FSD 2.5: "creating, updating, or changing a document's expiryDate
     * immediately re-evaluates its reminder schedule and enqueues any
     * due notifications" — the event-driven path, called directly by
     * DocumentController after a mutation.
     */
    public function evaluateDocument(Document $document): int
    {
        $document->loadMissing('employeeProfile.manager.user', 'employeeProfile.user', 'tenant');

        if ($document->last_renewed_at && $document->last_renewed_at->gt(now()->subDays(30))) {
            // FSD: status is RENEWED for 30 days post-renewal — the cycle
            // that just resolved has nothing left due.
            return 0;
        }

        $tenant = $document->tenant;
        $timezone = $tenant?->timezone ?? 'UTC';
        $today = now($timezone)->startOfDay();
        $expiry = Carbon::parse($document->expiry_date, $timezone)->startOfDay();
        $daysUntilExpiry = $today->diffInDays($expiry, false);

        $queued = 0;

        foreach (NotificationStage::orderedCases() as $stage) {
            $schedule = ReminderSchedule::resolve($document->tenant_id, $document->document_type_id, $stage);
            if (! $schedule) {
                continue;
            }

            $isDue = $daysUntilExpiry <= $schedule->offset_days;
            if (! $isDue) {
                continue;
            }

            $queued += $this->queueStage($document, $stage, $schedule->channels, $document->expiry_date);
        }

        $queued += $this->evaluateEscalation($document, $daysUntilExpiry, $today);

        return $queued;
    }

    /**
     * T23 — fires on the cadence (default every 7 days) starting the day
     * the document hits ON_EXPIRY, to the employee's Manager, unless an
     * active dismissal is in effect for this expiry cycle.
     */
    private function evaluateEscalation(Document $document, int $daysUntilExpiry, Carbon $today): int
    {
        if ($document->hasActiveEscalationDismissal()) {
            return 0;
        }

        $daysSinceExpiry = -$daysUntilExpiry;
        if ($daysSinceExpiry < 0) {
            return 0;
        }

        $schedule = ReminderSchedule::resolve($document->tenant_id, $document->document_type_id, NotificationStage::Escalation);
        if (! $schedule) {
            return 0;
        }

        $cadence = max(1, $schedule->cadence_days ?? 7);
        if ($daysSinceExpiry % $cadence !== 0) {
            return 0;
        }

        return $this->queueStage(
            $document,
            NotificationStage::Escalation,
            $schedule->channels,
            $document->expiry_date,
            $today->toDateString(),
        );
    }

    /** The scheduled-scan path (FSD 2.5's primary path) — every active document across every tenant. */
    public function evaluateAll(): array
    {
        $totals = ['documents_scanned' => 0, 'notifications_queued' => 0];

        Tenant::query()->each(function (Tenant $tenant) use (&$totals) {
            Document::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('expiry_date')
                ->with('employeeProfile.user', 'employeeProfile.manager.user', 'tenant')
                ->chunkById(200, function ($documents) use (&$totals) {
                    foreach ($documents as $document) {
                        $totals['documents_scanned']++;
                        $totals['notifications_queued'] += $this->evaluateDocument($document);
                    }
                });
        });

        return $totals;
    }

    /**
     * Queues one Notification per (recipient x channel) for this
     * document+stage, skipping any combination already queued for this
     * expiry_date_snapshot + occurrence_date (the dedupe unique index is
     * the final backstop; this check just avoids relying on catching a
     * constraint-violation exception on every scan). $occurrenceDate
     * defaults to the expiry date itself — correct for the four one-shot
     * stages, which only ever fire once per cycle; ESCALATION passes
     * today's date instead, since it can fire many times per cycle.
     */
    private function queueStage(
        Document $document,
        NotificationStage $stage,
        array $channels,
        $expiryDateSnapshot,
        ?string $occurrenceDate = null,
    ): int {
        $occurrenceDate ??= (string) $expiryDateSnapshot;

        $recipients = $stage === NotificationStage::Escalation
            ? $this->resolveEscalationRecipients($document)
            : $this->resolveRecipients($document);

        if (empty($recipients)) {
            return 0;
        }

        $queued = 0;

        foreach ($recipients as [$role, $user]) {
            foreach ($channels as $channelValue) {
                $channel = NotificationChannel::from($channelValue);

                $exists = Notification::query()
                    ->where('document_id', $document->id)
                    ->where('stage', $stage->value)
                    ->where('channel', $channel->value)
                    ->where('recipient_user_id', $user->id)
                    ->where('expiry_date_snapshot', $expiryDateSnapshot)
                    ->where('occurrence_date', $occurrenceDate)
                    ->exists();

                if ($exists) {
                    continue;
                }

                try {
                    Notification::create([
                        'tenant_id' => $document->tenant_id,
                        'document_id' => $document->id,
                        'stage' => $stage->value,
                        'channel' => $channel->value,
                        'recipient_role' => $role->value,
                        'recipient_user_id' => $user->id,
                        'status' => NotificationStatus::Queued->value,
                        'attempt_count' => 0,
                        'expiry_date_snapshot' => $expiryDateSnapshot,
                        'occurrence_date' => $occurrenceDate,
                        'queued_at' => now(),
                    ]);
                    $queued++;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Dedupe unique constraint raced with another scan —
                    // the row already exists, which is exactly what we
                    // wanted anyway. Anything else bubbles up.
                    if (! str_contains($e->getMessage(), 'notifications_dedupe_unique')) {
                        throw $e;
                    }
                }
            }
        }

        return $queued;
    }

    /**
     * FSD "escalating reminders to both the employee and HR/company in
     * parallel": the document's own employee (if the profile is linked
     * to an active user account) plus every Admin/HR user in the
     * tenant. An org-owned EXTENDED_ITEM with no employee_profile_id
     * just goes to Admin/HR.
     */
    private function resolveRecipients(Document $document): array
    {
        $recipients = [];

        $employeeUser = $document->employeeProfile?->user;
        if ($employeeUser && $employeeUser->status->value === 'ACTIVE') {
            $recipients[] = [NotificationRecipientRole::Employee, $employeeUser];
        }

        $adminHrUsers = User::query()
            ->where('tenant_id', $document->tenant_id)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Hr->value])
            ->where('status', 'ACTIVE')
            ->get();

        foreach ($adminHrUsers as $user) {
            $role = $user->role === UserRole::Admin ? NotificationRecipientRole::Admin : NotificationRecipientRole::Hr;
            $recipients[] = [$role, $user];
        }

        return $recipients;
    }

    /**
     * T23 — FSD "notifying the employee's Manager on a recurring
     * cadence." If there's no direct manager (or no active account
     * linked to one), fall back to Admin/HR so the escalation still
     * reaches somebody rather than silently going nowhere.
     */
    private function resolveEscalationRecipients(Document $document): array
    {
        $manager = $document->employeeProfile?->manager;
        $managerUser = $manager?->user;

        if ($managerUser && $managerUser->status->value === 'ACTIVE') {
            return [[NotificationRecipientRole::Manager, $managerUser]];
        }

        return array_filter(
            $this->resolveRecipients($document),
            fn ($pair) => in_array($pair[0], [NotificationRecipientRole::Admin, NotificationRecipientRole::Hr], true)
        );
    }
}
