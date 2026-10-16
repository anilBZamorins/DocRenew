<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T24 — the dispatch service needs somewhere to park a notification
     * between retry attempts (next_attempt_at: when it becomes eligible
     * to be picked up again) and somewhere to record WHY the last
     * attempt failed, for the Admin-visible delivery-issues list
     * (FSD 5: "surfaced on an Admin-visible delivery-issues list").
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('queued_at');
            $table->string('last_error')->nullable()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['next_attempt_at', 'last_error']);
        });
    }
};
