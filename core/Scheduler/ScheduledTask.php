<?php
declare(strict_types=1);

namespace Cajeer\Logs\Scheduler;

final class ScheduledTask
{
    public function __construct(public readonly string $name, public readonly string $expression, public readonly string $command) {}
}
