#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Repository;
use CajeerLogs\UpdateManager;

$manager = new UpdateManager(new Repository(Database::pdo()));
$failed = 0;
foreach ($manager->readiness() as $check) {
    echo ($check['ok'] ? '[ОК]   ' : '[СБОЙ] ') . $check['name'] . ' — ' . $check['message'] . PHP_EOL;
    if (!$check['ok']) { $failed++; }
}
exit($failed > 0 ? 1 : 0);
