<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T21 — the extension/MIME/size allowlist is the first line of defense
 * before a file ever reaches the virus scanner or encryptor: documents
 * are scans/PDFs/photos, never executables or scripts.
 */
class StoreAttachmentRequest extends FormRequest
{
    public const MAX_SIZE_KB = 10240; // 10 MB

    public function authorize(): bool
    {
        return $this->user()->can('uploadAttachment', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:'.self::MAX_SIZE_KB,
            ],
        ];
    }
}
