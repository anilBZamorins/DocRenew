<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T21 — extends db/schema.sql's `attachment_key` column with the
     * metadata a secure attachment pipeline needs: the original filename
     * (for downloads), the validated MIME type, the size (for quota/UI),
     * and when the virus scan that admitted it into the store completed
     * (FSD 4.1 "virus-scanned on upload" / FSD 10 audit requirements).
     * None of these are sensitive on their own — the actual file bytes
     * are encrypted at rest (AttachmentEncryptor) and never served except
     * via a short-lived signed URL.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('attachment_original_name')->nullable()->after('attachment_key');
            $table->string('attachment_mime_type', 128)->nullable()->after('attachment_original_name');
            $table->unsignedInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
            $table->timestamp('attachment_scanned_at')->nullable()->after('attachment_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_original_name', 'attachment_mime_type',
                'attachment_size_bytes', 'attachment_scanned_at',
            ]);
        });
    }
};
