<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;
final class RedisQueueDriver implements QueueDriverInterface
{
    public function push(QueuedJob $job): string { throw new \LogicException('Redis queue is not supported; use PostgreSQL.'); }
}
