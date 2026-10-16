<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T23 — FSD "Escalation flow": once a document passes expiry
     * unrenewed, a new ESCALATION stage begins notifying the employee's
     * Manager on a recurring cadence (default every 7 days) "until the
     * document is renewed or the role responsible dismisses it with a
     * reason" (FSD 5 design rules).
     *
     *  - reminder_schedules gets a 5th stage value and a cadence_days
     *    column (only meaningful for ESCALATION; the other four stages
     *    fire exactly once per cycle).
     *  - notifications gets an occurrence_date column: the four regular
     *    stages fire once per expiry cycle, so their dedupe key is
     *    effectively unchanged (occurrence_date = expiry_date_snapshot),
     *    but ESCALATION fires repeatedly within the same cycle, so it
     *    needs occurrence_date (today's date, the day a given escalation
     *    fired) in the dedupe key to allow more than one row per cycle
     *    while still deduping within a single day's scan runs.
     *  - documents gets the dismissal fields: a Manager or HR/Admin
     *    dismissing the escalation (DocumentController::dismissEscalation)
     *    stops the recurring notice until the document's expiry_date
     *    next changes (renewal or a manual edit resets these columns).
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE reminder_schedules MODIFY COLUMN stage ".
            "ENUM('THREE_MONTH','ONE_MONTH','FIVE_DAY','ON_EXPIRY','ESCALATION') NOT NULL"
        );
        Schema::table('reminder_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('cadence_days')->nullable()->after('offset_days');
        });

        DB::statement(
            "ALTER TABLE notifications MODIFY COLUMN stage ".
            "ENUM('THREE_MONTH','ONE_MONTH','FIVE_DAY','ON_EXPIRY','ESCALATION') NOT NULL"
        );
        Schema::table('notifications', function (Blueprint $table) {
            $table->date('occurrence_date')->nullable()->after('expiry_date_snapshot');
        });
        DB::statement('UPDATE notifications SET occurrence_date = expiry_date_snapshot WHERE occurrence_date IS NULL');
        Schema::table('notifications', function (Blueprint $table) {
            // Add the new index BEFORE dropping the old one: both start
            // with document_id, and the old one backs the document_id
            // foreign key — InnoDB refuses to drop it while nothing else
            // covers the FK, so the replacement has to exist first.
            $table->unique(
                ['document_id', 'stage', 'channel', 'recipient_user_id', 'expiry_date_snapshot', 'occurrence_date'],
                'notifications_dedupe_unique_v2'
            );
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_dedupe_unique');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('escalation_dismissed_at')->nullable()->after('last_renewed_at');
            $table->string('escalation_dismissed_reason')->nullable()->after('escalation_dismissed_at');
            $table->uuid('escalation_dismissed_by')->nullable()->after('escalation_dismissed_reason');
            $table->foreign('escalation_dismissed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['escalation_dismissed_by']);
            $table->dropColumn(['escalation_dismissed_at', 'escalation_dismissed_reason', 'escalation_dismissed_by']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unique(
                ['document_id', 'stage', 'channel', 'recipient_user_id', 'expiry_date_snapshot'],
                'notifications_dedupe_unique'
            );
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_dedupe_unique_v2');
            $table->dropColumn('occurrence_date');
        });
        DB::statement("ALTER TABLE notifications MODIFY COLUMN stage ENUM('THREE_MONTH','ONE_MONTH','FIVE_DAY','ON_EXPIRY') NOT NULL");

        Schema::table('reminder_schedules', function (Blueprint $table) {
            $table->dropColumn('cadence_days');
        });
        DB::statement("ALTER TABLE reminder_schedules MODIFY COLUMN stage ENUM('THREE_MONTH','ONE_MONTH','FIVE_DAY','ON_EXPIRY') NOT NULL");
    }
};
