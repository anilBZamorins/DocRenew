<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\Report;
use Throwable;

/**
 * T25 — orchestrates one Report row through PENDING/PROCESSING ->
 * COMPLETED/FAILED. Called either inline (ReportController::store(), for
 * small reports) or from GenerateReportJob (for large ones) — the actual
 * generation logic lives here once so both paths behave identically.
 */
class ReportGenerator
{
    public function __construct(
        private ReportDataBuilder $dataBuilder,
        private ReportFileWriter $fileWriter,
    ) {
    }

    public function generate(Report $report): void
    {
        $report->update(['status' => ReportStatus::Processing]);

        try {
            $user = $report->requestedByUser;

            if (! $user) {
                // The requesting user was deleted between request and
                // generation (e.g. a queued job running after offboarding) —
                // nothing safe to scope the report to.
                throw new \RuntimeException('Requesting user no longer exists; cannot determine report scope.');
            }

            $dataset = $this->dataBuilder->build($report->type, $user, $report->filters ?? []);

            $fileKey = $this->fileWriter->write(
                $report->tenant_id,
                $report->id,
                $report->format,
                $report->type->label(),
                $dataset['headers'],
                $dataset['rows'],
            );

            $report->update([
                'status' => ReportStatus::Completed,
                'row_count' => count($dataset['rows']),
                'file_key' => $fileKey,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $report->update([
                'status' => ReportStatus::Failed,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Re-throw so a queued job is recorded as failed in the
            // jobs/failed_jobs tables too — the Report row above is the
            // source of truth the API surfaces, this is just so queue
            // monitoring sees it as well.
            throw $e;
        }
    }
}
