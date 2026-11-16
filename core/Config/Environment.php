<?php
declare(strict_types=1);

namespace Cajeer\Logs\Config;

final class Environment
{
    /** @return array<string,string> */
    public static function load(string $file): array
    {
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new \RuntimeException('Environment must be a regular file.');
        }
        if (!file_exists($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read environment file.');
        }
        $values = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $match)) {
                throw new \RuntimeException('Invalid .env assignment at line ' . ($i + 1));
            }
            [, $key, $raw] = $match;
            if (isset($values[$key])) {
                throw new \RuntimeException('Duplicate .env key at line ' . ($i + 1));
            }
            if (str_starts_with($raw, '"')) {
                if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/D', $raw, $quoted)) {
                    throw new \RuntimeException('Invalid quoted .env value at line ' . ($i + 1));
                }
                $value = strtr($quoted[1], ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
            } elseif (str_starts_with($raw, "'")) {
                if (!preg_match("/^'([^']*)'\\s*(?:#.*)?$/D", $raw, $quoted)) {
                    throw new \RuntimeException('Invalid quoted .env value at line ' . ($i + 1));
                }
                $value = $quoted[1];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
            }
            $values[$key] = $value;
        }
        return $values;
    }
}
