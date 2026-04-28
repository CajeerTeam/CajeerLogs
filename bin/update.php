<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_env_guard.php';

use CajeerLogs\Database;
use CajeerLogs\Migrator;
use CajeerLogs\Repository;
use CajeerLogs\UpdateManager;

$action = $argv[1] ?? 'status';
$pdo = Database::pdo();
(new Migrator($pdo))->run();
$repo = new Repository($pdo);
$manager = new UpdateManager($repo);

try {
    switch ($action) {
        case 'status':
            echo json_encode(['status' => $manager->status(), 'readiness' => $manager->readiness()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
            break;
        case 'backup':
            echo json_encode($manager->backupOnly(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
            break;
        case 'update':
            echo json_encode($manager->updateFromGit(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
            break;
        case 'rollback':
            echo json_encode($manager->rollbackLast(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
            break;
        default:
            fwrite(STDERR, "Использование: php bin/update.php status|backup|update|rollback\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка обновления: {$e->getMessage()}\n");
    exit(1);
}
