<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FSD 5 / 6.2 — delivery log, one row per attempted send. The
     * scheduling engine (T22) only ever creates rows here in the QUEUED
     * state; turning QUEUED into SENT/DELIVERED/FAILED with retry/backoff
     * is the dispatch service's job (T24) and the actual provider calls
     * are T27-29. expiry_date_snapshot is what makes the per-stage
     * dedupe survive a renewal: renewing a document changes its
     * expiry_date, so the old snapshot no longer matches and the stage
     * becomes eligible again for the new cycle (FSD 8.3 "reminder
     * schedule resets against the new expiry date").
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');
            $table->enum('stage', ['THREE_MONTH', 'ONE_MONTH', 'FIVE_DAY', 'ON_EXPIRY']);
            $table->enum('channel', ['EMAIL', 'SMS', 'PUSH']);
            $table->enum('recipient_role', ['EMPLOYEE', 'ADMIN', 'HR', 'MANAGER']);
            $table->uuid('recipient_user_id')->nullable();
            $table->foreign('recipient_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->enum('status', ['QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'BOUNCED'])->default('QUEUED');
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->date('expiry_date_snapshot');
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_id', 'stage', 'channel', 'recipient_user_id', 'expiry_date_snapshot'],
                'notifications_dedupe_unique'
            );
            $table->index('tenant_id');
            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
