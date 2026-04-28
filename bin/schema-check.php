#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

$schemaPath = $root . '/app/Internal/schema.pgsql.sql';
$migratorPath = $root . '/app/Migrator.php';
$repoPath = $root . '/app/Repository.php';
$versionPath = $root . '/VERSION';

$schema = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
$migrator = is_file($migratorPath) ? (string)file_get_contents($migratorPath) : '';
$repo = is_file($repoPath) ? (string)file_get_contents($repoPath) : '';
$version = is_file($versionPath) ? trim((string)file_get_contents($versionPath)) : '';

$check('schema.pgsql.sql существует', $schema !== '', $schemaPath);
$check('Migrator.php существует', $migrator !== '', $migratorPath);
$check('Repository.php существует', $repo !== '', $repoPath);

preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i', $schema, $schemaTables);
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i', $migrator, $migratorTables);
$schemaTableList = array_values(array_unique($schemaTables[1] ?? []));
$migratorTableList = array_values(array_unique($migratorTables[1] ?? []));
foreach ($schemaTableList as $table) {
    $check('Таблица из snapshot есть в Migrator: ' . $table, in_array($table, $migratorTableList, true));
}
foreach ($migratorTableList as $table) {
    $check('Таблица из Migrator есть в snapshot: ' . $table, in_array($table, $schemaTableList, true));
}

preg_match_all('/CREATE INDEX IF NOT EXISTS\s+([a-z0-9_]+)/i', $schema, $schemaIndexes);
preg_match_all('/CREATE INDEX IF NOT EXISTS\s+([a-z0-9_]+)/i', $migrator, $migratorIndexes);
$schemaIndexList = array_values(array_unique($schemaIndexes[1] ?? []));
$migratorIndexList = array_values(array_unique($migratorIndexes[1] ?? []));
foreach ($schemaIndexList as $index) {
    $check('Индекс из snapshot есть в Migrator: ' . $index, in_array($index, $migratorIndexList, true));
}

foreach (['events_limit_per_minute', 'bytes_limit_per_minute'] as $field) {
    $check('Repository::botTokens выбирает поле ' . $field, str_contains($repo, 'bt.' . $field));
    $check('schema содержит поле ' . $field, str_contains($schema, $field));
    $check('Migrator содержит поле ' . $field, str_contains($migrator, $field));
}

$check('OpenAPI отвечает фактическому ingest-коду 200/inserted', str_contains((string)file_get_contents($root . '/openapi.yaml'), "'200':") && str_contains((string)file_get_contents($root . '/openapi.yaml'), 'inserted:'));
$check('Версия задана', $version !== '');
$check('Миграционная версия 010 зарегистрирована', str_contains($migrator, "010_github_repository_ops"));
$check('Миграционная версия 011 зарегистрирована', str_contains($migrator, "011_ops_hardening"));
$check('schema содержит ingest_rate_counters', str_contains($schema, 'ingest_rate_counters'));
$check('Migrator содержит ingest_rate_counters', str_contains($migrator, 'ingest_rate_counters'));
$check('Repository использует атомарный PostgreSQL rate limit', str_contains($repo, 'atomicPgsqlIngestRateLimitViolation') && str_contains($repo, 'ON CONFLICT (bot_token_id, bucket_at)'));


exit($failed > 0 ? 1 : 0);
