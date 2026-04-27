<?php
declare(strict_types=1);

namespace CajeerLogs;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $payload = [
            'ts' => gmdate('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => self::normalizeContext($context),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = '{"ts":"' . gmdate('c') . '","level":"ERROR","message":"failed_to_encode_log_line"}';
        }

        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }

        // Fallback for early boot/permission failures: still push the message into PHP-FPM/Nginx error log.
        @error_log('[cajeer-logs] ' . $line);
    }

    private static function normalizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                    'trace' => $value->getTraceAsString(),
                ];
                continue;
            }

            if (is_resource($value)) {
                $context[$key] = 'resource';
            }
        }

        return $context;
    }
}
