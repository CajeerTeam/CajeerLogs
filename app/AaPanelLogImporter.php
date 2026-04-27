<?php
declare(strict_types=1);

namespace CajeerLogs;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AaPanelLogImporter
{
    public function __construct(private readonly Repository $repo) {}

    public function listSources(?string $baseDir = null): array
    {
        $baseDir = $baseDir ?: Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs');
        if (!is_dir($baseDir) || !is_readable($baseDir)) {
            return [];
        }

        $files = glob(rtrim($baseDir, '/') . '/*.log') ?: [];
        $sources = [];
        foreach ($files as $file) {
            $source = $this->sourceFromFile($file);
            if ($source === null) {
                continue;
            }
            $stat = @stat($file) ?: [];
            $source['size_bytes'] = (int)($stat['size'] ?? 0);
            $source['inode'] = (int)($stat['ino'] ?? 0);
            $source['mtime'] = isset($stat['mtime']) ? date('Y-m-d H:i:s', (int)$stat['mtime']) : null;
            $source['readable'] = is_readable($file);
            $offset = $this->repo->aaPanelOffset($source['site'], $source['log_type'], $source['file_path']);
            $source['offset_bytes'] = (int)($offset['offset_bytes'] ?? 0);
            $source['imported_lines'] = (int)($offset['imported_lines'] ?? 0);
            $source['last_import_at'] = $offset['last_import_at'] ?? null;
            $source['inode_saved'] = $offset['inode'] ?? null;
            $source['rotation_detected'] = (int)($offset['rotation_detected'] ?? 0);
            $sources[] = $source;
        }

        usort($sources, static fn(array $a, array $b): int => [$a['site'], $a['log_type']] <=> [$b['site'], $b['log_type']]);
        return $sources;
    }

    public function importAll(?string $baseDir = null, ?string $site = null, int $maxLines = 1000): array
    {
        $maxLines = max(1, min(Env::int('AAPANEL_LOG_IMPORT_MAX_LINES', 2000), $maxLines));
        $summary = ['sources' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($this->listSources($baseDir) as $source) {
            if ($site !== null && $site !== '' && $source['site'] !== $site) {
                continue;
            }
            $summary['sources']++;
            try {
                $result = $this->importSource($source, $maxLines);
                $summary['inserted'] += $result['inserted'];
                $summary['skipped'] += $result['skipped'];
            } catch (RuntimeException $e) {
                $summary['errors'][] = $source['file_path'] . ': ' . $e->getMessage();
            }
        }
        return $summary;
    }

    /** @param array{site:string,log_type:string,file_path:string} $source */
    public function importSource(array $source, int $maxLines = 1000): array
    {
        $file = (string)$source['file_path'];
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Файл недоступен для чтения.');
        }

        $site = (string)$source['site'];
        $type = (string)$source['log_type'];
        $offsetRow = $this->repo->aaPanelOffset($site, $type, $file);
        $offset = (int)($offsetRow['offset_bytes'] ?? 0);
        $stat = @stat($file) ?: [];
        $inode = (int)($stat['ino'] ?? 0);
        $mtime = (int)($stat['mtime'] ?? time());
        $size = filesize($file);
        if ($size === false) {
            throw new RuntimeException('Не удалось определить размер файла.');
        }
        $firstLineHash = $this->firstLineHash($file);
        $rotationDetected = false;
        if ($offset > $size) {
            $offset = 0;
            $rotationDetected = true;
        }
        if ($offsetRow && !empty($offsetRow['inode']) && (int)$offsetRow['inode'] !== $inode && $offset >= $size) {
            $offset = 0;
            $rotationDetected = true;
        }
        if ($offsetRow && !empty($offsetRow['first_line_hash']) && $firstLineHash !== null && (string)$offsetRow['first_line_hash'] !== $firstLineHash && $offset >= $size) {
            $offset = 0;
            $rotationDetected = true;
        }

        $handle = fopen($file, 'rb');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть файл.');
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $inserted = 0;
        $skipped = 0;
        $lines = 0;
        while (!feof($handle) && $lines < $maxLines) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $lines++;
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                $skipped++;
                continue;
            }
            $event = $type === 'error' ? $this->parseErrorLine($line) : $this->parseAccessLine($line);
            $event['context']['aapanel_site'] = $site;
            $event['context']['aapanel_log_type'] = $type;
            $event['context']['aapanel_log_file'] = $file;
            $this->repo->insertAaPanelSiteLog($site, $type, $line, $event);
            $inserted++;
        }

        $newOffset = ftell($handle) ?: $offset;
        fclose($handle);
        $this->repo->saveAaPanelOffset($site, $type, $file, $newOffset, $inserted, [
            'inode' => $inode,
            'file_size' => $size,
            'first_line_hash' => $firstLineHash,
            'mtime' => $mtime,
            'rotation_detected' => $rotationDetected,
        ]);

        return ['inserted' => $inserted, 'skipped' => $skipped, 'offset_bytes' => $newOffset, 'rotation_detected' => $rotationDetected];
    }

    private function firstLineHash(string $file): ?string
    {
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return null;
        }
        $line = fgets($handle);
        fclose($handle);
        if ($line === false) {
            return null;
        }
        return hash('sha256', rtrim($line, "\r\n"));
    }

    /** @return array{site:string,log_type:string,file_path:string}|null */
    private function sourceFromFile(string $file): ?array
    {
        $name = basename($file);
        $site = null;
        $type = 'access';

        if (str_ends_with($name, '.error.log')) {
            $site = substr($name, 0, -strlen('.error.log'));
            $type = 'error';
        } elseif (str_ends_with($name, '.access.log')) {
            $site = substr($name, 0, -strlen('.access.log'));
            $type = 'access';
        } elseif (str_ends_with($name, '.log')) {
            $site = substr($name, 0, -strlen('.log'));
            $type = 'access';
        }

        if (!$site || str_contains($site, '/')) {
            return null;
        }

        return ['site' => $site, 'log_type' => $type, 'file_path' => $file];
    }

    /** @return array{ts:string,level:string,logger:string,message:string,context:array<string,mixed>} */
    private function parseAccessLine(string $line): array
    {
        $context = ['raw' => $line];
        $ts = gmdate('Y-m-d H:i:s');
        $level = 'INFO';
        $message = $line;

        $pattern = '/^(?<ip>\S+)\s+\S+\s+\S+\s+\[(?<time>[^\]]+)\]\s+"(?<method>\S+)\s+(?<path>.*?)\s+(?<proto>[^\"]+)"\s+(?<status>\d{3})\s+(?<bytes>\S+)\s+"(?<referer>[^"]*)"\s+"(?<ua>[^"]*)"/';
        if (preg_match($pattern, $line, $m)) {
            $status = (int)$m['status'];
            $level = $status >= 500 ? 'ERROR' : ($status >= 400 ? 'WARNING' : 'INFO');
            $ts = $this->parseNginxAccessTime($m['time']);
            $message = $m['method'] . ' ' . $m['path'] . ' → ' . $status;
            $context = [
                'remote_addr' => $m['ip'],
                'method' => $m['method'],
                'path' => $m['path'],
                'protocol' => $m['proto'],
                'status' => $status,
                'bytes' => $m['bytes'] === '-' ? null : (int)$m['bytes'],
                'referer' => $m['referer'] !== '-' ? $m['referer'] : null,
                'user_agent' => $m['ua'] !== '-' ? $m['ua'] : null,
                'raw' => $line,
            ];
        }

        return [
            'ts' => $ts,
            'level' => $level,
            'logger' => 'nginx.access',
            'message' => $message,
            'context' => $context,
        ];
    }

    /** @return array{ts:string,level:string,logger:string,message:string,exception:?string,context:array<string,mixed>} */
    private function parseErrorLine(string $line): array
    {
        $ts = gmdate('Y-m-d H:i:s');
        $severity = null;
        $level = 'ERROR';
        $message = $line;
        $context = ['raw' => $line];

        if (preg_match('/^(?<date>\d{4}\/\d{2}\/\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+\[(?<severity>[^\]]+)\]\s+(?<rest>.*)$/', $line, $m)) {
            $ts = str_replace('/', '-', $m['date']) . ' ' . $m['time'];
            $severity = strtolower(trim(explode(' ', $m['severity'])[0]));
            $message = trim($m['rest']);
            $context = ['severity' => $severity, 'raw' => $line];
            $level = match ($severity) {
                'crit', 'critical', 'alert', 'emerg' => 'CRITICAL',
                'warn', 'warning' => 'WARNING',
                'notice', 'info' => 'INFO',
                default => 'ERROR',
            };
        }

        return [
            'ts' => $ts,
            'level' => $level,
            'logger' => 'nginx.error',
            'message' => $message,
            'exception' => $line,
            'context' => $context,
        ];
    }

    private function parseNginxAccessTime(string $value): string
    {
        $dt = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $value);
        if (!$dt) {
            return gmdate('Y-m-d H:i:s');
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
