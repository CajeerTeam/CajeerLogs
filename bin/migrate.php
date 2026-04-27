#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Migrator;

$migrator = new Migrator(Database::pdo());
$migrator->run();
echo "Миграции выполнены. Драйвер: " . Database::driver() . PHP_EOL;
