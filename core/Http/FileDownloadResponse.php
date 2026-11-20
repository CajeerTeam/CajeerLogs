<?php
declare(strict_types=1);
namespace Cajeer\Logs\Http;

/**
 * Stream a private export from the exact descriptor that was verified.
 * Never reopen the pathname after verifying its SHA-256: a worker or local
 * process may replace the pathname before the web server starts streaming.
 */
final class FileDownloadResponse extends Response
{
    /** Clones share ownership of the verified descriptor, not just its resource ID. */
    private ?object $streamLease = null;
    private readonly int $bytes;

    public function __construct(string $file, string $name, string $format, int $bytes, ?string $checksum = null)
    {
        if ($bytes < 0 || $bytes > 268435456 || ($checksum !== null && !preg_match('/^[a-f0-9]{64}$/D', $checksum))) {
            throw new HttpException(410, 'export_integrity_failed', 'Invalid export metadata.');
        }
        // lstat + fstat fence the pathname against symlink and inode swaps at open.
        clearstatcache(true, $file);
        $pathStat = @lstat($file);
        if (!is_array($pathStat) || ($pathStat['mode'] & 0170000) !== 0100000) {
            throw new HttpException(410, 'export_missing', 'Export file is unavailable.');
        }
        $stream = @fopen($file, 'rb');
        if ($stream === false) throw new HttpException(410, 'export_missing', 'Export file is unavailable.');
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000 ||
                $opened['dev'] !== $pathStat['dev'] || $opened['ino'] !== $pathStat['ino'] ||
                $opened['size'] !== $bytes || $opened['size'] !== $pathStat['size']) {
                throw new HttpException(410, 'export_integrity_failed', 'Export file changed or fails integrity verification.');
            }
            if ($checksum !== null) {
                $hash = hash_init('sha256');
                $read = hash_update_stream($hash, $stream);
                if ($read !== $bytes || !hash_equals($checksum, hash_final($hash)) || !rewind($stream)) {
                    throw new HttpException(410, 'export_integrity_failed', 'Export file changed or fails integrity verification.');
                }
            }
            $this->bytes = $bytes;
            parent::__construct('', 200, [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8',
                'Content-Length' => (string) $bytes,
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            // ErrorHandler decorates the response via withHeader(), which clones
            // it. A shared owner prevents the original object's destruction
            // from closing a descriptor that the decorated clone must stream.
            $this->streamLease = new class($stream) {
                public function __construct(public mixed $stream) {}
                public function __destruct() {
                    if (is_resource($this->stream)) fclose($this->stream);
                }
            };
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public function send(bool $head = false): void
    {
        $stream = $this->streamLease?->stream;
        if (!is_resource($stream)) throw new HttpException(410, 'export_missing', 'Export file is unavailable.');
        $now = fstat($stream);
        if (!is_array($now) || $now['size'] !== $this->bytes || !rewind($stream)) {
            throw new HttpException(410, 'export_integrity_failed', 'Export file changed before download.');
        }
        parent::send(true);
        if ($head) return;
        $remaining = $this->bytes;
        while ($remaining > 0) {
            $chunk = fread($stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                error_log('CajeerLogs export stream read failed');
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }

}
