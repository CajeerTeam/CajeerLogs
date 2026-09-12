<?php
declare(strict_types=1);

namespace Cajeer\Logs\Queue;

final class PostgresQueueDriver
{
    public function push(QueuedJob $job): void {}
}
