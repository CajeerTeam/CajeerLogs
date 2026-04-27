#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;

$pdo = Database::pdo();
if (Database::driver() !== 'pgsql') {
    fwrite(STDERR, "postgres-optimize.php работает только с PostgreSQL. Текущий драйвер: " . Database::driver() . PHP_EOL);
    exit(1);
}

$commands = [
    'CREATE EXTENSION IF NOT EXISTS pg_trgm',
    'CREATE INDEX IF NOT EXISTS idx_log_events_message_trgm ON log_events USING gin (message gin_trgm_ops)',
    'CREATE INDEX IF NOT EXISTS idx_log_events_exception_trgm ON log_events USING gin (exception gin_trgm_ops)',
    'CREATE INDEX IF NOT EXISTS idx_log_events_logger_trgm ON log_events USING gin (logger gin_trgm_ops)',
    'CREATE INDEX IF NOT EXISTS idx_log_events_received_at ON log_events (received_at DESC)',
    'CREATE INDEX IF NOT EXISTS idx_ingest_batches_token_received ON ingest_batches (bot_token_id, received_at DESC)',
];

foreach ($commands as $sql) {
    echo "[postgres-optimize] {$sql}\n";
    $pdo->exec($sql);
}

echo "Оптимизация PostgreSQL завершена. Шаблон партиционирования: sql/postgres_partitioning_optional.sql\n";
