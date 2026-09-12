<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class SecurityHeaders
{
    public function headers(): array
{
    return ['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin'];
}
}
