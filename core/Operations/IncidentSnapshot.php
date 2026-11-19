<?php
declare(strict_types=1);

namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\SecureFile;

/** Save a sanitized operator report under a private, no-overwrite file. */
final class IncidentSnapshot
{
    public static function save(string $storageDirectory, array $report): string
    {
        if (($report['scope'] ?? null) !== 'operations-triage' || !in_array($report['status'] ?? null, ['ok','degraded','critical'], true)) {
            throw new DatabaseException('Refusing to save an invalid incident report.');
        }
        if (!is_dir($storageDirectory)) throw new DatabaseException('Storage directory does not exist.');
        SecureFile::assertDirectory($storageDirectory);
        $directory = $storageDirectory . '/incident-reports';
        if (!is_dir($directory)) {
            $old = umask(0077);
            try { if (!@mkdir($directory, 0700) && !is_dir($directory)) throw new DatabaseException('Cannot create private incident directory.'); }
            finally { umask($old); }
        }
        SecureFile::assertDirectory($directory);
        $path = $directory . '/incident-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(12)) . '.json';
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($json) > 131072) throw new DatabaseException('Incident report exceeds the safe size limit.');
        $old = umask(0077);
        try { $stream = @fopen($path, 'xb'); }
        finally { umask($old); }
        if ($stream === false) throw new DatabaseException('Cannot exclusively create incident report.');
        $created = fstat($stream);
        try {
            SecureFile::verifyHandle($stream, $path);
            $remaining = $json;
            while ($remaining !== '') {
                $written = fwrite($stream, $remaining);
                if ($written === false || $written === 0) throw new DatabaseException('Cannot write incident report.');
                $remaining = substr($remaining, $written);
            }
            if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) throw new DatabaseException('Cannot persist incident report.');
            SecureFile::verifyHandle($stream, $path);
        } catch (\Throwable $error) {
            fclose($stream);
            // Only remove the same private inode that this operation created.
            $current = @lstat($path);
            if ($created !== false && $current !== false && $created['ino'] === $current['ino']
                && $created['dev'] === $current['dev'] && SecureFile::privateFile($path)) @unlink($path);
            throw $error;
        }
        fclose($stream);
        return $path;
    }
}
