<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

/**
 * FSD 10 "Encryption at rest": attachment bytes are encrypted
 * application-side (AES-256-CBC via Laravel's Encrypter / APP_KEY —
 * the same key used for Document::document_number_enc) before being
 * written to the 'attachments' disk, and decrypted only in-memory when
 * streamed back through a short-lived signed URL. The disk/bucket never
 * holds plaintext, so a storage-layer compromise alone does not expose
 * document contents.
 */
class AttachmentEncryptor
{
    public function encrypt(string $rawContents): string
    {
        return Crypt::encrypt($rawContents);
    }

    public function decrypt(string $encryptedContents): string
    {
        return Crypt::decrypt($encryptedContents);
    }
}
