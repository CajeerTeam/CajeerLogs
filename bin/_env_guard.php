<?php
declare(strict_types=1);

use CajeerLogs\Env;
use CajeerLogs\RuntimeDiagnostics;

$script = basename((string)($argv[0] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
$envOptional = ['self-test.php', 'wiki-check.php', 'schema-check.php', 'release-check.php', 'forbidden-scan.php', 'full-check.php', 'update-env-check.php'];
$explicitLocal = in_array('--local', $argv ?? [], true) || Env::get('APP_ENV', '') === 'local';

if (!RuntimeDiagnostics::envExists() && !$explicitLocal && !in_array($script, $envOptional, true)) {
    fwrite(STDERR, "Файл .env обязателен для {$script} на production-сервере. Восстанови его из storage/backups/updates/*/env.snapshot или запусти с --local для локальной проверки.\n");
    exit(1);
}

try {
    RuntimeDiagnostics::assertProductionLockSafe();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
