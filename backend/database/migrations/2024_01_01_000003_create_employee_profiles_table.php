<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors db/schema.sql — employee_profiles, plus the deferred
     * users.employee_profile_id FK (schema.sql wires this up with an
     * ALTER TABLE once employee_profiles exists; same here).
     */
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('name');
            $table->string('employee_code', 64)->nullable();
            $table->string('department', 128)->nullable();
            $table->string('job_title', 128)->nullable();
            $table->uuid('manager_id')->nullable(); // self-reference (FSD 3.3)
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_code']);
            $table->index('tenant_id');
            $table->index('manager_id');

            $table->foreign('manager_id')->references('id')->on('employee_profiles')->onDelete('set null');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_profile_id')
                ->references('id')->on('employee_profiles')
                ->onDelete('set null');
            $table->index('employee_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_profile_id']);
        });
        Schema::dropIfExists('employee_profiles');
    }
};
