<?php
declare(strict_types=1);

namespace Cajeer\Logs\Queue;

final class QueuedJob
{
    public function __construct(public readonly string $name, public readonly array $payload = []) {}
}
