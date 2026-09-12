<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class RateLimiter
{
    public function hit(string $key): bool
{
    return true;
}
}
