<?php
declare(strict_types=1);

namespace Cajeer\Logs\Api\Controller;

final class LogsController
{
    /** @return array<string,mixed> */
public function index(): array
{
    return ['data' => [], 'meta' => ['mode' => 'skeleton']];
}
}
