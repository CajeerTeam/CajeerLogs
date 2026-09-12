<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class CsrfProtection
{
    public function token(): string
{
    return bin2hex(random_bytes(16));
}
}
