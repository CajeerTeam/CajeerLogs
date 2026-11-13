<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

final class CsrfProtection
{
    public function token(): string { return bin2hex(random_bytes(32)); }
    public function verify(string $expected, ?string $provided): bool
    {
        return $provided !== null && preg_match('/^[a-f0-9]{64}$/D',$provided) === 1 && hash_equals($expected,$provided);
    }
}
