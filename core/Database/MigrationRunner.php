<?php
declare(strict_types=1);

namespace Cajeer\Logs\Database;

final class MigrationRunner
{
    /** @return list<string> */
public function plannedDrivers(): array
{
    return ['pgsql', 'mysql', 'mariadb', 'sqlite', 'clickhouse'];
}
}
