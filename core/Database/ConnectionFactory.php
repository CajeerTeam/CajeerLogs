<?php
declare(strict_types=1);

namespace Cajeer\Logs\Database;

final class ConnectionFactory
{
    public function make(string $connection): \PDO
{
    return match ($connection) {
        'sqlite' => new \PDO('sqlite:' . dirname(__DIR__, 2) . '/storage/database/cajeerlogs.sqlite'),
        default => throw new \RuntimeException("Database connection [$connection] is not configured in skeleton."),
    };
}
}
