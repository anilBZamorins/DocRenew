<?php

namespace App\Services;

interface VirusScanner
{
    /** Scans the file at $path and returns whether it is safe to admit into the attachment store. */
    public function scan(string $path): VirusScanResult;
}
