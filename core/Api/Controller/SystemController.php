<?php
declare(strict_types=1);

namespace Cajeer\Logs\Api\Controller;

final class SystemController
{
    /** @return array<string,mixed> */
public function report(): array
{
    return [
        'php' => PHP_VERSION,
        'target' => 'PHP 8.5 ready',
        'server' => 'Nginx primary, Apache optional',
    ];
}
}
