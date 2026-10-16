<?php

namespace App\Services;

/** FSD 4.1: "Document attachments are virus-scanned on upload before being accepted into the object store." */
class VirusScanResult
{
    public function __construct(
        public readonly bool $clean,
        public readonly string $scanner,
        public readonly ?string $reason = null,
    ) {
    }

    public static function clean(string $scanner): self
    {
        return new self(true, $scanner);
    }

    public static function rejected(string $scanner, string $reason): self
    {
        return new self(false, $scanner, $reason);
    }
}
