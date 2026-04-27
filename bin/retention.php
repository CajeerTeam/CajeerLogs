#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Repository;

$started = microtime(true);
$repo = new Repository(Database::pdo());
$rules = [
    'TRACE' => Env::int('RETENTION_DEBUG_DAYS', 7),
    'DEBUG' => Env::int('RETENTION_DEBUG_DAYS', 7),
    'INFO' => Env::int('RETENTION_INFO_DAYS', 30),
    'WARNING' => Env::int('RETENTION_WARN_DAYS', 90),
    'ERROR' => Env::int('RETENTION_ERROR_DAYS', 365),
    'CRITICAL' => Env::int('RETENTION_ERROR_DAYS', 365),
    'AUDIT' => Env::int('RETENTION_AUDIT_DAYS', 730),
    'SECURITY' => Env::int('RETENTION_AUDIT_DAYS', 730),
];
$total = 0;
if (Env::bool('RETENTION_ARCHIVE_BEFORE_DELETE', false)) {
    foreach ([['all', Env::int('RETENTION_ARCHIVE_DAYS', 90)], ['aapanel_access', Env::int('RETENTION_AAPANEL_ACCESS_DAYS', 14)], ['aapanel_error', Env::int('RETENTION_AAPANEL_ERROR_DAYS', 90)]] as [$source, $days]) {
        $archive = $repo->archiveLogsOlderThan((int)$days, (string)$source);
        echo "archive source={$source}: archived=" . $archive['archived'] . ", deleted=" . $archive['deleted'] . ", file=" . ($archive['file'] ?? '-') . "\n";
    }
}
foreach ($rules as $level => $days) {
    $deleted = $repo->deleteOlderThan($level, $days);
    $total += $deleted;
    echo "{$level}: deleted={$deleted}, keep_days={$days}\n";
}
foreach ($repo->retentionBySource() as $row) {
    $total += (int)$row['deleted'];
    echo $row['rule'] . ": deleted=" . $row['deleted'] . ", keep_days=" . $row['keep_days'] . "\n";
}
$repo->recordCronRun('retention', 'ok', 'deleted=' . $total, ['deleted' => $total], $started);
echo "Total deleted={$total}\n";
