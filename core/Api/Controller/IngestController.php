<?php
declare(strict_types=1);

namespace Cajeer\Logs\Api\Controller;

final class IngestController
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
public function ingest(array $payload): array
{
    return [
        'accepted' => true,
        'mode' => 'skeleton',
        'received' => count($payload['events'] ?? [$payload]),
    ];
}
}
