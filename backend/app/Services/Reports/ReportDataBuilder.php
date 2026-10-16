<?php

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * T25 / FSD 4.3 "Reports" — builds the (headers, rows) dataset for each of
 * the four report types. Deliberately returns plain arrays rather than
 * writing a file itself: ReportFileWriter owns the CSV/PDF rendering, so
 * this class only owns "what data goes in the report" and, critically,
 * the FSD 3.2 role scoping ("Compliance reports | Full | Full | Team
 * scope | None") that applies identically no matter which format is
 * requested.
 */
class ReportDataBuilder
{
    /**
     * A cheap pre-count used only to decide sync-vs-queued generation
     * (config('reports.async_row_threshold')) — NOT an exact preview of
     * the final row count. For Overdue it skips the computed-status
     * post-filter (counts "expiry_date in the past" rather than true
     * EXPIRED), and that's fine: a mild over-estimate only ever pushes a
     * borderline report onto the async path a little more eagerly, it
     * never produces a wrong report.
     */
    public function estimateRowCount(ReportType $type, User $user, array $filters): int
    {
        return match ($type) {
            ReportType::ComplianceSnapshot => $this->scopedDocumentQuery($user, $filters)->count(),
            ReportType::Overdue => $this->scopedDocumentQuery($user, $filters)
                ->where('expiry_date', '<', now()->toDateString())->count(),
            ReportType::NotificationActivity => Notification::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereBetween('queued_at', $this->dateRange($filters, 30))
                ->count(),
            ReportType::RenewalHistory => AuditLog::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('action', 'document.renew')
                ->whereBetween('created_at', $this->dateRange($filters, 90))
                ->count(),
        };
    }

    /**
     * @return array{headers: array<int,string>, rows: array<int,array<int,string|null>>}
     */
    public function build(ReportType $type, User $user, array $filters): array
    {
        return match ($type) {
            ReportType::ComplianceSnapshot => $this->complianceSnapshot($user, $filters),
            ReportType::Overdue => $this->overdue($user, $filters),
            ReportType::NotificationActivity => $this->notificationActivity($user, $filters),
            ReportType::RenewalHistory => $this->renewalHistory($user, $filters),
        };
    }

    private function complianceSnapshot(User $user, array $filters): array
    {
        $query = $this->scopedDocumentQuery($user, $filters)->with(['employeeProfile', 'documentType']);

        $rows = [];

        foreach ($query->get() as $document) {
            $rows[] = [
                $document->employeeProfile?->name,
                $document->employeeProfile?->department,
                $document->documentType?->name,
                $document->category,
                $document->document_number_masked,
                $document->issue_date?->toDateString(),
                $document->expiry_date?->toDateString(),
                $document->status,
                $document->last_renewed_at?->toDateString(),
            ];
        }

        return [
            'headers' => ['Employee', 'Department', 'Document Type', 'Category', 'Document Number', 'Issue Date', 'Expiry Date', 'Status', 'Last Renewed'],
            'rows' => $rows,
        ];
    }

    private function overdue(User $user, array $filters): array
    {
        $query = $this->scopedDocumentQuery($user, $filters)
            ->where('expiry_date', '<', now()->toDateString())
            ->with(['employeeProfile', 'documentType']);

        $rows = [];

        // status is a computed accessor (today vs expiry_date in the
        // tenant's timezone) — a document the WHERE above caught as
        // "expiry_date in the past" can still read as RENEWED if it was
        // renewed within the last 30 days, so that has to be filtered
        // here rather than in SQL (same pattern as
        // DocumentController::index()'s in-memory status filter).
        foreach ($query->get() as $document) {
            if ($document->status !== 'EXPIRED') {
                continue;
            }

            $daysOverdue = (int) now()->startOfDay()->diffInDays($document->expiry_date, false) * -1;

            $escalationStatus = match (true) {
                $document->hasActiveEscalationDismissal() => 'DISMISSED',
                $document->notifications()->where('stage', 'ESCALATION')->exists() => 'ESCALATING',
                default => 'NOT_YET_ESCALATED',
            };

            $rows[] = [
                $document->employeeProfile?->name,
                $document->employeeProfile?->department,
                $document->documentType?->name,
                $document->document_number_masked,
                $document->expiry_date?->toDateString(),
                (string) $daysOverdue,
                $escalationStatus,
            ];
        }

        return [
            'headers' => ['Employee', 'Department', 'Document Type', 'Document Number', 'Expiry Date', 'Days Overdue', 'Escalation Status'],
            'rows' => $rows,
        ];
    }

    private function notificationActivity(User $user, array $filters): array
    {
        $query = Notification::query()->where('tenant_id', $user->tenant_id)
            ->whereBetween('queued_at', $this->dateRange($filters, 30))
            ->with(['document.employeeProfile', 'recipient']);

        if (! $user->role->isTenantWide()) {
            $visibleEmployeeIds = $this->visibleEmployeeProfileIds($user);
            $query->whereHas('document', fn (Builder $q) => $q->whereIn('employee_profile_id', $visibleEmployeeIds));
        }

        $rows = [];

        foreach ($query->orderBy('queued_at')->get() as $notification) {
            $rows[] = [
                $notification->queued_at?->toDateTimeString(),
                $notification->document?->employeeProfile?->name,
                $notification->stage->value,
                $notification->channel->value,
                $notification->recipient?->email,
                $notification->status->value,
                (string) $notification->attempt_count,
                $notification->sent_at?->toDateTimeString(),
                $notification->failed_at?->toDateTimeString(),
                $notification->last_error,
            ];
        }

        return [
            'headers' => ['Queued At', 'Employee', 'Stage', 'Channel', 'Recipient', 'Status', 'Attempts', 'Sent At', 'Failed At', 'Last Error'],
            'rows' => $rows,
        ];
    }

    private function renewalHistory(User $user, array $filters): array
    {
        $query = AuditLog::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('action', 'document.renew')
            ->whereBetween('created_at', $this->dateRange($filters, 90))
            ->with('actingUser');

        $rows = [];
        $visibleEmployeeIds = $user->role->isTenantWide() ? null : $this->visibleEmployeeProfileIds($user);

        // audit_logs is a generic append-only log (entity_type/entity_id,
        // not a FK) so the related Document is loaded per row rather than
        // eager-loaded via a relation — acceptable here since renewal
        // events are comparatively rare (one audit row per renewal, not
        // per scan cycle) and this report is already paginated by date
        // range.
        foreach ($query->orderBy('created_at')->get() as $log) {
            $document = Document::with('employeeProfile', 'documentType')->find($log->entity_id);

            if (! $document) {
                continue; // document since hard-deleted; nothing left to report on.
            }

            if ($visibleEmployeeIds !== null && ! in_array($document->employee_profile_id, $visibleEmployeeIds, true)) {
                continue;
            }

            $rows[] = [
                $log->created_at?->toDateTimeString(),
                $document->employeeProfile?->name,
                $document->documentType?->name,
                $document->document_number_masked,
                $log->metadata['previous_expiry_date'] ?? null,
                $log->metadata['new_expiry_date'] ?? null,
                $log->actingUser?->email,
            ];
        }

        return [
            'headers' => ['Renewed At', 'Employee', 'Document Type', 'Document Number', 'Previous Expiry', 'New Expiry', 'Renewed By'],
            'rows' => $rows,
        ];
    }

    /**
     * FSD 3.2 "Compliance reports ... Team scope" for Manager — mirrors
     * DocumentController::scopeVisible() — plus the document_type_id /
     * department filters shared by the Compliance Snapshot and Overdue
     * reports.
     */
    private function scopedDocumentQuery(User $user, array $filters): Builder
    {
        $query = Document::query()->where('tenant_id', $user->tenant_id);

        if (! $user->role->isTenantWide()) {
            $query->whereIn('employee_profile_id', $this->visibleEmployeeProfileIds($user));
        }

        if (! empty($filters['document_type_id'])) {
            $query->where('document_type_id', $filters['document_type_id']);
        }
        if (! empty($filters['department'])) {
            $query->whereHas('employeeProfile', fn (Builder $q) => $q->where('department', $filters['department']));
        }

        return $query;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dateRange(array $filters, int $defaultDays): array
    {
        $from = isset($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : now()->subDays($defaultDays)->startOfDay();
        $to = isset($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    /** @return array<int,string> */
    private function visibleEmployeeProfileIds(User $user): array
    {
        return EmployeeProfile::query()
            ->tap(fn (Builder $q) => EmployeeProfile::visibleTo($q, $user))
            ->pluck('id')
            ->all();
    }
}
