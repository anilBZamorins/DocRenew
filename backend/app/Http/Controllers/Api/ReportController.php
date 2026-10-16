<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Jobs\GenerateReportJob;
use App\Models\AuditLog;
use App\Models\Report;
use App\Services\Reports\ReportDataBuilder;
use App\Services\Reports\ReportGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * T25 / FSD 4.3 "Reports" — POST /reports requests a report generation
 * job; GET /reports/:id polls status and, once COMPLETED, is the signal
 * to call GET /reports/:id/download (a short-lived signed URL, mirroring
 * T21's attachment pattern — a compliance report can contain the same
 * sensitive document data, so it gets the same "never a direct link"
 * treatment).
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportDataBuilder $dataBuilder)
    {
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $report = Report::create([
            'tenant_id' => $user->tenant_id,
            'requested_by' => $user->id,
            'type' => $data['type'],
            'format' => $data['format'],
            'status' => ReportStatus::Pending,
            'filters' => $data['filters'] ?? [],
            'requested_at' => now(),
        ]);

        AuditLog::record('report.requested', 'Report', $report->id, [
            'type' => $data['type'],
            'format' => $data['format'],
        ]);

        $estimatedRows = $this->dataBuilder->estimateRowCount($report->type, $user, $report->filters ?? []);
        $threshold = config('reports.async_row_threshold');

        if ($estimatedRows > $threshold) {
            GenerateReportJob::dispatch($report);

            // On a real (non-sync) queue connection this still reads back
            // PENDING, as expected — the worker hasn't picked it up yet.
            // On the 'sync' driver (used in local/test environments)
            // dispatch() above already ran the job to completion, but
            // against a SEPARATE deserialized Report instance (queued
            // jobs always round-trip through serialization, even on the
            // sync driver) — refresh() re-reads this controller's copy
            // so the response reflects whatever the job actually did
            // instead of the stale pre-dispatch PENDING state.
            return (new ReportResource($report->refresh()))->response()->setStatusCode(202);
        }

        // Small enough to generate inline — the client gets a COMPLETED
        // (or FAILED) report in the same response instead of having to
        // poll at all.
        try {
            app(ReportGenerator::class)->generate($report);
        } catch (\Throwable) {
            // ReportGenerator has already recorded the FAILED status +
            // error_message on the Report row itself — nothing more to
            // do here; the response below reflects that failed state
            // rather than turning it into a 500.
        }

        return (new ReportResource($report->refresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, Report $report): ReportResource
    {
        $this->authorize('view', $report);

        return new ReportResource($report);
    }

    /**
     * Issues the short-lived signed download URL (same shape as
     * AttachmentController::show()) rather than streaming the file
     * directly from this already-authenticated endpoint, so the actual
     * download link can be handed to e.g. a background "open in new
     * tab" without re-sending the bearer token.
     */
    public function downloadUrl(Request $request, Report $report): JsonResponse
    {
        $this->authorize('download', $report);

        if ($report->status !== ReportStatus::Completed || ! $report->hasFile()) {
            return response()->json(['message' => 'This report is not yet available for download.'], 409);
        }

        $ttlMinutes = 5;

        $url = URL::temporarySignedRoute(
            'reports.stream',
            now()->addMinutes($ttlMinutes),
            ['report' => $report->id, 'actor_id' => $request->user()->id]
        );

        AuditLog::record('report.link_issued', 'Report', $report->id, [
            'expires_in_minutes' => $ttlMinutes,
        ]);

        return response()->json(['url' => $url, 'expires_in' => $ttlMinutes * 60]);
    }

    /**
     * The actual download, guarded purely by the 'signed' middleware
     * (routes/api.php) — no auth:sanctum/active check, mirroring T21's
     * AttachmentController::stream() exactly: the signature IS the
     * authorization, time-boxed and tied to the report it was issued
     * for, with the actor id riding along for audit attribution.
     */
    public function stream(Request $request, Report $report): StreamedResponse
    {
        if ($report->status !== ReportStatus::Completed || ! $report->hasFile()) {
            abort(404);
        }

        $key = $report->getRawOriginal('file_key');
        $contents = Storage::disk('reports')->get($key);

        AuditLog::create([
            'tenant_id' => $report->tenant_id,
            'acting_user_id' => $request->query('actor_id'),
            'action' => 'report.downloaded',
            'entity_type' => 'Report',
            'entity_id' => $report->id,
            'metadata' => ['type' => $report->type->value, 'format' => $report->format->value],
        ]);

        $extension = strtolower($report->format->value);
        $filename = "{$report->type->value}_{$report->id}.{$extension}";
        $contentType = $extension === 'csv' ? 'text/csv' : 'application/pdf';

        return response()->streamDownload(
            function () use ($contents) {
                echo $contents;
            },
            $filename,
            ['Content-Type' => $contentType]
        );
    }
}
