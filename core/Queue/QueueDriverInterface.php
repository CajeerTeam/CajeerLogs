<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;
interface QueueDriverInterface
{
    /** Persist a job within the caller's transaction. */
    public function push(QueuedJob $job): string;
}
