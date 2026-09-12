<?php
declare(strict_types=1);

namespace Cajeer\Logs\Database;

interface ConnectionFactoryInterface
{
    public function make(string $connection): \PDO;
}
