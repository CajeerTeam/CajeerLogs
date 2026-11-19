<?php
// PostgreSQL PDO boolean normalization for security-sensitive policy decisions.
declare(strict_types=1);
namespace Cajeer\Logs\Database;

final class PostgresBoolean
{
    public static function required(mixed $value): bool
    {
        if (in_array($value, [true, 1, '1', 't', 'true'], true)) return true;
        if (in_array($value, [false, 0, '0', 'f', 'false'], true)) return false;
        throw new DatabaseException('Unexpected PostgreSQL boolean value.');
    }
}
