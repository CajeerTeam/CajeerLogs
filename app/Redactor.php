<?php
declare(strict_types=1);

namespace CajeerLogs;

final class Redactor
{
    private const PLACEHOLDER = '[REDACTED]';

    public static function redactMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redactString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = (string)$k;
                if (self::isSensitiveKey($key)) {
                    $out[$k] = self::PLACEHOLDER;
                } else {
                    $out[$k] = self::redactMixed($v);
                }
            }
            return $out;
        }
        return $value;
    }

    public static function redactString(string $text): string
    {
        $patterns = [
            '/(bot\d{6,}:[A-Za-z0-9_\-]{20,})/i',
            '/(xox[baprs]-[A-Za-z0-9\-]+)/i',
            '/(gh[pousr]_[A-Za-z0-9_]{20,})/i',
            '/(sk-[A-Za-z0-9_\-]{20,})/i',
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key)\s*[=:]\s*)([^\s,;\}\]\"]+)/i',
            '/((?:Authorization|X-Log-Token)\s*:\s*)(Bearer\s+)?([^\s]+)/i',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, static function (array $m): string {
                if (count($m) >= 4) {
                    return $m[1] . ($m[2] ?? '') . self::PLACEHOLDER;
                }
                if (count($m) >= 3) {
                    return $m[1] . self::PLACEHOLDER;
                }
                return self::PLACEHOLDER;
            }, $text) ?? $text;
        }
        return $text;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return (bool)preg_match('/password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|authorization/i', $key);
    }
}
