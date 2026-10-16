<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\AuditLog;
use App\Models\Document;
use App\Services\AttachmentEncryptor;
use App\Services\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * T21 — Secure document repository & encrypted storage (FSD 4.1 / 10).
 *
 * Pipeline: upload -> allowlist validation (StoreAttachmentRequest) ->
 * virus scan (VirusScanner, rejects before anything touches the store)
 * -> encrypt (AttachmentEncryptor, AES-256 via APP_KEY) -> write to the
 * private 'attachments' disk under a non-guessable key. The raw key is
 * never returned to a client (Document::$hidden) — downloads only ever
 * happen through a short-lived signed URL (default 5 minutes), and
 * every grant and every actual download is audit-logged.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly VirusScanner $scanner,
        private readonly AttachmentEncryptor $encryptor,
    ) {
    }

    public function store(StoreAttachmentRequest $request, Document $document): JsonResponse
    {
        $file = $request->file('file');

        $scan = $this->scanner->scan($file->getRealPath());

        if (! $scan->clean) {
            AuditLog::record('document.attachment.rejected', 'Document', $document->id, [
                'scanner' => $scan->scanner,
                'reason' => $scan->reason,
                'original_name' => $file->getClientOriginalName(),
            ]);

            return response()->json([
                'message' => 'The uploaded file failed the security scan and was not stored.',
                'errors' => ['file' => [$scan->reason ?? 'Rejected by virus scan.']],
            ], 422);
        }

        $previousKey = $document->getRawOriginal('attachment_key');

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $key = sprintf('%s/%s/%s.%s', $document->tenant_id, $document->id, (string) Str::uuid(), $extension);

        $encrypted = $this->encryptor->encrypt(file_get_contents($file->getRealPath()));
        Storage::disk('attachments')->put($key, $encrypted);

        $document->update([
            'attachment_key' => $key,
            'attachment_original_name' => $file->getClientOriginalName(),
            'attachment_mime_type' => $file->getMimeType(),
            'attachment_size_bytes' => $file->getSize(),
            'attachment_scanned_at' => now(),
        ]);

        // Only drop the old object once the new one is safely written.
        if ($previousKey) {
            Storage::disk('attachments')->delete($previousKey);
        }

        AuditLog::record('document.attachment.upload', 'Document', $document->id, [
            'scanner' => $scan->scanner,
            'original_name' => $document->attachment_original_name,
            'size_bytes' => $document->attachment_size_bytes,
            'replaced_previous' => (bool) $previousKey,
        ]);

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /**
     * Issues a short-lived signed URL rather than a direct storage
     * location (FSD 10 "object-store files are never served directly").
     * The authorizing user's id travels inside the signature so the
     * eventual download (stream(), below) can still be attributed to
     * them in the audit log even though that route has no session.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('downloadAttachment', $document);

        if (! $document->hasAttachment()) {
            return response()->json(['message' => 'This document has no attachment.'], 404);
        }

        $ttlMinutes = 5;

        $url = URL::temporarySignedRoute(
            'attachments.stream',
            now()->addMinutes($ttlMinutes),
            ['document' => $document->id, 'actor_id' => $request->user()->id]
        );

        AuditLog::record('document.attachment.link_issued', 'Document', $document->id, [
            'expires_in_minutes' => $ttlMinutes,
        ]);

        return response()->json(['url' => $url, 'expires_in' => $ttlMinutes * 60]);
    }

    /**
     * The actual download endpoint. Protected purely by the 'signed'
     * middleware (routes/api.php) — no auth:sanctum/active check, since
     * the whole point of a signed URL is that it carries its own
     * time-boxed authorization and doesn't need a live session (e.g. a
     * mobile push notification deep link). Laravel rejects an invalid or
     * expired signature before this method ever runs.
     */
    public function stream(Request $request, Document $document): StreamedResponse
    {
        if (! $document->hasAttachment()) {
            abort(404);
        }

        $key = $document->getRawOriginal('attachment_key');
        $encrypted = Storage::disk('attachments')->get($key);
        $raw = $this->encryptor->decrypt($encrypted);

        AuditLog::create([
            'tenant_id' => $document->tenant_id,
            'acting_user_id' => $request->query('actor_id'),
            'action' => 'document.attachment.download',
            'entity_type' => 'Document',
            'entity_id' => $document->id,
            'metadata' => ['original_name' => $document->attachment_original_name],
        ]);

        return response()->streamDownload(
            function () use ($raw) {
                echo $raw;
            },
            $document->attachment_original_name ?? 'attachment',
            ['Content-Type' => $document->attachment_mime_type ?? 'application/octet-stream']
        );
    }
}
