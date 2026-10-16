<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mirrors db/schema.sql — audit_logs. */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->uuid('acting_user_id')->nullable();
            $table->string('action', 128);
            $table->string('entity_type', 64)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('acting_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index('tenant_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
