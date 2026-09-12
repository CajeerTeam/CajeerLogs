<?php
declare(strict_types=1);

namespace Cajeer\Logs\Log;

final class Redactor
{
    private const SECRET_KEYS = ['password', 'token', 'secret', 'authorization', 'cookie'];

/** @param array<string,mixed> $payload @return array<string,mixed> */
public function redact(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
            $payload[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $payload[$key] = $this->redact($value);
        }
    }
    return $payload;
}
}
