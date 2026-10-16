<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors db/schema.sql — documents (FSD 4.1, 6.2). Covers both
     * EMPLOYEE_DOCUMENT and EXTENDED_ITEM categories (T20 scope note:
     * db/schema.sql's CHECK constraints are reproduced here verbatim —
     * MySQL 8.0.16+/MariaDB 10.2+ enforce them; the app-level validation
     * in StoreDocumentRequest/UpdateDocumentRequest mirrors the same
     * rule so the error surfaces as a clean 422, not a DB exception.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->uuid('employee_profile_id')->nullable();
            $table->foreignUuid('document_type_id')->references('id')->on('document_types')->onDelete('restrict');
            $table->enum('category', ['EMPLOYEE_DOCUMENT', 'EXTENDED_ITEM'])->default('EMPLOYEE_DOCUMENT');
            $table->string('item_label')->nullable();
            $table->text('document_number_enc')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date');
            $table->string('attachment_key', 512)->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_profile_id')->references('id')->on('employee_profiles')->onDelete('cascade');

            $table->index('tenant_id');
            $table->index('employee_profile_id');
            $table->index('document_type_id');
            $table->index(['tenant_id', 'expiry_date']);
            $table->index(['tenant_id', 'category']);
        });

        // MySQL/MariaDB CHECK constraints — Blueprint has no fluent ->check()
        // helper, so these are added as raw DDL, matching db/schema.sql
        // exactly (chk_documents_employee_required / chk_documents_item_label_required).
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT chk_documents_employee_required ".
            "CHECK (category <> 'EMPLOYEE_DOCUMENT' OR employee_profile_id IS NOT NULL)"
        );
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT chk_documents_item_label_required ".
            "CHECK (category <> 'EXTENDED_ITEM' OR employee_profile_id IS NOT NULL OR item_label IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
