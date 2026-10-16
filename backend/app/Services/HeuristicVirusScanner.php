<?php

namespace App\Services;

/**
 * Fallback scanner used only when no real AV engine (ClamAV) is
 * installed on the host — e.g. local dev. It is NOT a substitute for
 * ClamAvVirusScanner in production (FSD 4.1 requires an actual
 * signature-based scan); it exists so uploads still get a real,
 * auditable check rather than silently skipping one. It rejects
 * executable/script magic bytes and double extensions regardless of the
 * declared MIME type, and otherwise defers to the upload's own
 * extension/size allowlist (StoreAttachmentRequest).
 */
class HeuristicVirusScanner implements VirusScanner
{
    private const BLOCKED_MAGIC_BYTES = [
        "MZ" => 'Windows PE/EXE executable signature',
        "\x7fELF" => 'Linux ELF executable signature',
        "#!" => 'shell/script shebang',
        "<?php" => 'embedded PHP script',
        "<script" => 'embedded script tag',
    ];

    public function scan(string $path): VirusScanResult
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return VirusScanResult::rejected('heuristic', 'Unable to read uploaded file for scanning.');
        }

        $head = fread($handle, 512);
        fclose($handle);
        $headLower = strtolower($head);

        foreach (self::BLOCKED_MAGIC_BYTES as $signature => $reason) {
            if (str_starts_with($head, $signature) || str_contains($headLower, strtolower($signature))) {
                return VirusScanResult::rejected('heuristic', "Blocked: {$reason}.");
            }
        }

        if (preg_match('/\.(php|js|exe|sh|bat|cmd|com|scr|jar|msi)\./i', basename($path))) {
            return VirusScanResult::rejected('heuristic', 'Blocked: double extension indicates disguised executable content.');
        }

        return VirusScanResult::clean('heuristic');
    }
}
