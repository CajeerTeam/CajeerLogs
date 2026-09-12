<?php
declare(strict_types=1);

namespace Cajeer\Logs\Log;

final class IngestEvent
{
    /** @param array<string,mixed> $context */
public function __construct(
    public readonly string $source,
    public readonly string $level,
    public readonly string $message,
    public readonly array $context = [],
) {}

/** @return array<string,mixed> */
public function toArray(): array
{
    return [
        'source' => $this->source,
        'level' => $this->level,
        'message' => $this->message,
        'context' => $this->context,
    ];
}
}
