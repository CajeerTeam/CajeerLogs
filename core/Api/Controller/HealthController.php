<?php
declare(strict_types=1);

namespace Cajeer\Logs\Api\Controller;

final class HealthController
{
    /** @return array<string,mixed> */
public function __invoke(): array
{
    return [
        'status' => 'ok',
        'service' => 'CajeerLogs',
        'version' => '0.1.0-initial',
    ];
}
}
