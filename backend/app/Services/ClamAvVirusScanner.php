<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Production scanner: shells out to clamdscan (preferred — talks to the
 * clamd daemon, no per-call signature-DB reload) or clamscan as a
 * fallback. Bound by AppServiceProvider only when one of those binaries
 * is actually present on the host, per FSD 4.1.
 */
class ClamAvVirusScanner implements VirusScanner
{
    public function __construct(private readonly string $binary)
    {
    }

    public function scan(string $path): VirusScanResult
    {
        $process = new Process([$this->binary, '--no-summary', $path]);
        $process->setTimeout(30);
        $process->run();

        // clamdscan/clamscan exit codes: 0 = clean, 1 = virus found, 2 = error.
        if ($process->getExitCode() === 0) {
            return VirusScanResult::clean('clamav');
        }

        if ($process->getExitCode() === 1) {
            return VirusScanResult::rejected('clamav', trim($process->getOutput()) ?: 'Malware signature detected.');
        }

        throw new ProcessFailedException($process);
    }
}
