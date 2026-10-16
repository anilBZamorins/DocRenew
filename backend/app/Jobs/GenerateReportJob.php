<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Reports\ReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * T25 / FSD 4.3: "Reports above a configurable row-count threshold are
 * generated asynchronously by the Reporting Service ... rather than
 * blocking the requesting page." ReportController::store() dispatches
 * this when the estimated row count exceeds config('reports.async_row_threshold');
 * the client polls GET /reports/:id until status is COMPLETED or FAILED.
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // ReportGenerator already records a clear FAILED reason; a blind retry wouldn't fix a bad filter or a deleted user.

    public function __construct(private readonly Report $report)
    {
    }

    public function handle(ReportGenerator $generator): void
    {
        $generator->generate($this->report);
    }
}
