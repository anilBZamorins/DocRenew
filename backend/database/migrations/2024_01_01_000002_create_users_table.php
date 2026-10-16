<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors db/schema.sql — users (sign-in identities, FSD 3.1/3.4).
     * The employee_profile_id FK to employee_profiles is added in the
     * employee_profiles migration, once that table exists (same order
     * as db/schema.sql).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('email');
            $table->string('password_hash'); // bcrypt, cost 12 — Hash::make(), see config/hashing.php
            $table->enum('role', ['ADMIN', 'HR', 'MANAGER', 'EMPLOYEE']);
            $table->uuid('employee_profile_id')->nullable();
            $table->enum('status', ['INVITED', 'ACTIVE', 'SUSPENDED'])->default('INVITED');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
