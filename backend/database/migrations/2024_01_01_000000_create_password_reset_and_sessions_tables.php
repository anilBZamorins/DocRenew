<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * password_reset_tokens is keyed by (tenant_id, email) rather than
     * Laravel's stock email-only primary key: DocRenew's uniqueness
     * constraint on users is (tenant_id, email), so the same email can
     * exist under two different tenants (FSD 3.4). An email-only key
     * would let a reset token issued for one tenant's account reset a
     * different tenant's same-email account — PasswordController uses
     * this table directly rather than Laravel's PasswordBroker facade,
     * which assumes an email-only key.
     *
     * sessions is kept from the default skeleton for the 'database'
     * session driver configured in .env.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'email']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
