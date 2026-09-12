<?php
declare(strict_types=1);

namespace Cajeer\Logs\Log;

final class LogRepository
{
    public function store(IngestEvent $event): string
{
    return hash('sha256', json_encode($event->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @return list<array<string,mixed>> */
public function search(array $filters = []): array
{
    return [];
}
}
