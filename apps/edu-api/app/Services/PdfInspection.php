<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class PdfInspection
{
    public function inspect(string $path): void
    {
        $scanner = app()->bound(PdfMalwareScanner::class);
        abort_if(config('pdf-storage.require_malware_scan') && ! $scanner, 503, 'PDF malware inspection is unavailable.');

        $data = file_get_contents($path, length: SchoolEntitlements::FREE['max_pdf_bytes'] + 1);
        abort_unless(is_string($data) && strlen($data) <= SchoolEntitlements::FREE['max_pdf_bytes']
            && preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)\r?\n/', $data)
            && preg_match('/startxref\s+([0-9]{1,8})\s+%%EOF\s*\z/', $data, $xref)
            && preg_match('/\A(?:xref\s|[0-9]+\s+[0-9]+\s+obj\b)/', substr($data, (int) $xref[1], 64))
            && (new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf', 422, 'Invalid PDF.');

        $metadata = $this->parser('/usr/bin/pdfinfo', [$path]);
        abort_unless(preg_match('/^Pages:[ \t]+([1-9]\d{0,2})$/m', $metadata, $pages) && (int) $pages[1] <= 250
            && preg_match('/^Encrypted:[ \t]+no$/m', $metadata)
            && preg_match('/^Form:[ \t]+none$/m', $metadata)
            && preg_match('/^JavaScript:[ \t]+no$/m', $metadata), 422, 'PDF inspection rejected the document.');
        abort_unless(trim($this->parser('/usr/bin/pdfinfo', ['-js', $path])) === '', 422, 'PDF inspection rejected the document.');
        abort_unless(trim($this->parser('/usr/bin/pdfdetach', ['-list', $path])) === '0 embedded files', 422, 'PDF inspection rejected the document.');

        if ($scanner) {
            try {
                $clean = app(PdfMalwareScanner::class)->isClean($path);
            } catch (\Throwable) {
                abort(503, 'PDF malware inspection is unavailable.');
            }
            abort_unless($clean, 422, 'PDF malware inspection rejected the document.');
        }
    }

    private function parser(string $binary, array $arguments): string
    {
        abort_unless(is_executable('/usr/bin/prlimit') && is_executable($binary), 503, 'PDF inspection is unavailable.');
        $outputBytes = 0;
        $overLimit = false;
        $process = new Process([
            '/usr/bin/env', '-i', 'LC_ALL=C', 'HOME=/nonexistent', 'TMPDIR=/nonexistent',
            '/usr/bin/prlimit', '--as=268435456', '--cpu=2:3', '--fsize=0', '--core=0', '--nofile=32', '--',
            $binary, ...$arguments,
        ], '/', timeout: 4);

        try {
            $process->run(function ($type, string $chunk) use (&$outputBytes, &$overLimit): void {
                $outputBytes += strlen($chunk);
                if ($outputBytes > 16384 && ! $overLimit) {
                    $overLimit = true;
                    throw new \OverflowException;
                }
            });
        } catch (\OverflowException) {
            abort(422, 'PDF inspection output exceeded its limit.');
        } catch (ExceptionInterface) {
            abort(503, 'PDF inspection did not complete.');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        abort_unless($process->isSuccessful() && trim($process->getErrorOutput()) === '', 422, 'PDF inspection rejected the document.');

        return $process->getOutput();
    }
}
