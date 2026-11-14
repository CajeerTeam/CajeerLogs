<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;
final class RabbitMqQueueDriver implements QueueDriverInterface
{
    public function push(QueuedJob $job): string { throw new \LogicException('RabbitMq queue is not supported; use PostgreSQL.'); }
}
