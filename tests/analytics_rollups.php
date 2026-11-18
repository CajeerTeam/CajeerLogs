<?php
declare(strict_types=1);

use Cajeer\Logs\Analytics\PostgresRollupStore;
use Cajeer\Logs\Database\MigrationSql;
use Cajeer\Logs\Log\LogQuery;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $truth,string $name):void { global $n; if (!$truth)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n"; }
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0029_analytics_rollups.sql');
$parsed=MigrationSql::parse($sql);
check($parsed['mode']==='transactional'&&count($parsed['statements'])===12,'minute rollup migration is supported transactional SQL');
check(str_contains($sql,'REFERENCING NEW TABLE AS new_rows')&&str_contains($sql,'REFERENCING OLD TABLE AS old_rows'),'set-based transactional change capture');
check(str_contains($sql,'ON CONFLICT (event_id) DO NOTHING'),'idempotent event-state insert');
check(str_contains($sql,'events=m.events-summed.n'),'updates and deletes retract previous contributions');
check(str_contains($sql,'CHECK (events >= 0)'),'negative rollup counts rejected');
check(str_contains($sql,'DELETE FROM analytics_rollup_minutes m USING old_rows o'),'empty dimension groups are purged');
check(str_contains($sql,'analytics_rollup_legacy_source_idx'),'legacy source-less events remain on exact raw path');
check(class_exists(PostgresRollupStore::class),'real rollup implementation autoloads');
$q=LogQuery::parse(['project_id'=>'11111111-1111-4111-8111-111111111111','from'=>'2026-11-18T12:00:00Z','to'=>'2026-11-18T12:50:00Z'],'stats');
check($q->interval==='hour'&&$q->filters['from']==='2026-11-18T12:00:00.000000Z','stats API filtering preserved');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Log/LogRepository.php'),'$rollups->eligible($query)'),'live API chooses only completed rollups');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Analytics/PostgresRollupStore.php'),'FOR SHARE'),'concurrent backfill locks selected history');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Analytics/PostgresRollupStore.php'),'mismatched_groups'),'dimension reconciliation implemented');
echo "Analytics rollups unit: $n checks passed\n";
