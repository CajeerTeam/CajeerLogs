<?php
declare(strict_types=1);

namespace Cajeer\Logs\Cache;

final class RedisCacheStore
{
    public function ping(): bool
{
    return true;
}
}
