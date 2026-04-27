<?php
declare(strict_types=1);

namespace CajeerLogs;

final class Security
{
    public static function currentActor(): string
    {
        return Auth::username();
    }

    public static function tokenHash(string $rawToken): string
    {
        $pepper = Env::require('LOGS_TOKEN_PEPPER');
        return hash_hmac('sha256', $rawToken, $pepper);
    }

    public static function bearerOrHeaderToken(): ?string
    {
        $header = $_SERVER['HTTP_X_LOG_TOKEN'] ?? null;
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', (string)$auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function randomToken(): string
    {
        return 'clog_' . bin2hex(random_bytes(32));
    }

    public static function csrfToken(string $action): string
    {
        return self::csrfTokenForBucket($action, intdiv(time(), 3600));
    }

    public static function verifyCsrfToken(string $action, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $bucket = intdiv(time(), 3600);
        foreach ([$bucket, $bucket - 1] as $candidateBucket) {
            if (hash_equals(self::csrfTokenForBucket($action, $candidateBucket), $token)) {
                return true;
            }
        }
        return false;
    }

    public static function verifyRequestSignature(string $rawToken, string $rawBody, string $timestamp, string $nonce, string $signature): array
    {
        $timestamp = trim($timestamp);
        $nonce = trim($nonce);
        $signature = strtolower(trim($signature));
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return [false, 'Отсутствуют X-Log-Timestamp, X-Log-Nonce или X-Log-Signature.'];
        }

        $time = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        if ($time === false || $time <= 0) {
            return [false, 'Некорректный X-Log-Timestamp.'];
        }

        $skew = Env::int('INGEST_SIGNATURE_SKEW_SECONDS', 300);
        if (abs(time() - $time) > $skew) {
            return [false, 'Подпись просрочена или timestamp слишком далеко от времени сервера.'];
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return [false, 'Некорректный формат X-Log-Signature.'];
        }

        if (strlen($nonce) < 12 || strlen($nonce) > 128) {
            return [false, 'Некорректный X-Log-Nonce.'];
        }

        $canonical = self::signatureCanonicalPayload($timestamp, $nonce, $rawBody);
        $expected = hash_hmac('sha256', $canonical, $rawToken);
        if (!hash_equals($expected, $signature)) {
            return [false, 'Подпись запроса не совпадает.'];
        }

        return [true, null];
    }

    public static function signatureCanonicalPayload(string $timestamp, string $nonce, string $rawBody): string
    {
        return trim($timestamp) . "\n" . trim($nonce) . "\n" . hash('sha256', $rawBody);
    }

    private static function csrfTokenForBucket(string $action, int $bucket): string
    {
        $pepper = Env::require('LOGS_TOKEN_PEPPER');
        $user = self::currentActor();
        return hash_hmac('sha256', $action . '|' . $user . '|' . $bucket, $pepper);
    }


    public static function privacyHash(string $type, string|int|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $pepper = Env::get('PRIVACY_HASH_PEPPER', Env::get('LOGS_TOKEN_PEPPER', ''));
        if ($pepper === '') {
            return hash('sha256', $type . ':' . (string)$value);
        }
        return hash_hmac('sha256', $type . ':' . (string)$value, $pepper);
    }

    public static function maskSecret(?string $value): string
    {
        $value = (string)$value;
        if ($value === '') {
            return '—';
        }
        if (strlen($value) <= 12) {
            return str_repeat('•', strlen($value));
        }
        return substr($value, 0, 6) . '…' . substr($value, -4);
    }


    public static function uiIpAllowed(): bool
    {
        $allow = trim((string)Env::get('UI_IP_ALLOWLIST', ''));
        if ($allow === '') {
            return true;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', $allow) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && self::cidrContains($entry, $ip)) {
                return true;
            }
        }
        return false;
    }

    private static function cidrContains(string $cidr, string $ip): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null || !ctype_digit($bits)) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $bitsInt = max(0, min(32, (int)$bits));
        $mask = $bitsInt === 0 ? 0 : (-1 << (32 - $bitsInt));
        return (($ipLong & $mask) === ($subnetLong & $mask));
    }


    public static function isSafeInternalPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/')) {
            return false;
        }
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $path);
    }

    public static function safeInternalPath(string $path, string $fallback = '/'): string
    {
        return self::isSafeInternalPath($path) ? trim($path) : $fallback;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
