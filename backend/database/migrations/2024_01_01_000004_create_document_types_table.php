<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mirrors db/schema.sql — document_types. */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('name', 128);
            $table->enum('category', ['EMPLOYEE_DOCUMENT', 'EXTENDED_ITEM'])->default('EMPLOYEE_DOCUMENT');
            $table->integer('sort_order')->default(0);
            $table->boolean('retired')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
