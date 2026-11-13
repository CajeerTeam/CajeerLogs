<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

/** Validated, normalized event. Source is the registered slug, never a client-supplied project. */
final readonly class IngestEvent
{
    public function __construct(
        public string $source,
        public string $level,
        public string $message,
        public \stdClass $context,
        public string $timestamp,
        public ?string $service = null,
        public ?string $host = null,
        public ?string $traceId = null,
        public ?string $requestId = null,
        public array $tags = [],
    ) {}
}
