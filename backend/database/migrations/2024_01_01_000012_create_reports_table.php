<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T25 / FSD 4.3 "Reports" + FSD "Reporting Service": tracks each
     * requested report job (compliance snapshot, overdue/expired,
     * notification activity, renewal history) through PENDING ->
     * PROCESSING -> COMPLETED/FAILED. Small reports (below the
     * configurable row-count threshold, see config/reports.php) are
     * generated inline during the request; large ones are dispatched to
     * the queue (GenerateReportJob) so the requesting page isn't blocked —
     * either way the client polls GET /reports/:id the same way.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');

            // COMPLIANCE_SNAPSHOT / OVERDUE / NOTIFICATION_ACTIVITY / RENEWAL_HISTORY
            $table->string('type', 32);
            // CSV / PDF
            $table->string('format', 8);
            // PENDING / PROCESSING / COMPLETED / FAILED
            $table->string('status', 16)->default('PENDING');

            // The request's filters (date range, employee/department scope, etc.) —
            // kept so a Manager's "team scope" restriction can be verified/re-applied
            // if the job runs later, and so the finished report is reproducible.
            $table->json('filters')->nullable();

            $table->unsignedInteger('row_count')->nullable();
            // The 'reports' disk's object key — never exposed directly (mirrors
            // documents.attachment_key / T21's signed-URL-only pattern).
            $table->string('file_key')->nullable();
            $table->string('error_message')->nullable();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index(['tenant_id', 'requested_by']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
