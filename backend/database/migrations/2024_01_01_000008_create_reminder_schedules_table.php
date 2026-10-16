<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FSD 4.5 / 6.2 — per-tenant reminder stage offsets, with an optional
     * per-document-type override. A row with document_type_id = NULL is
     * the tenant-wide default for that stage (FSD 4.5: "tenant-wide
     * default stages ... with optional per-document-type overrides").
     */
    public function up(): void
    {
        Schema::create('reminder_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreignUuid('document_type_id')->nullable()
                ->references('id')->on('document_types')->onDelete('cascade');
            $table->enum('stage', ['THREE_MONTH', 'ONE_MONTH', 'FIVE_DAY', 'ON_EXPIRY']);
            $table->unsignedSmallInteger('offset_days'); // days before (positive) or after (0/negative) expiry
            $table->json('channels'); // e.g. ["EMAIL","SMS","PUSH"]
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type_id', 'stage'], 'reminder_schedules_scope_stage_unique');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_schedules');
    }
};
