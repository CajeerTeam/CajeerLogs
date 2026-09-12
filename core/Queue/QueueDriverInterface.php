<?php
declare(strict_types=1);

namespace Cajeer\Logs\Queue;

interface QueueDriverInterface
{
    public function push(QueuedJob $job): void;
}
