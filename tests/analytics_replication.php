<?php
declare(strict_types=1);
use Cajeer\Logs\Analytics\AnalyticsReplicator;
use Cajeer\Logs\Database\MigrationSql;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}
$r=['event_id'=>'11111111-1111-4111-8111-111111111111','project_id'=>'22222222-2222-4222-8222-222222222222',
    'source_id'=>'33333333-3333-4333-8333-333333333333',
    'occurred_at'=>'2026-11-18 13:10:00+00','received_at'=>'2026-11-18 13:10:01.000123+00',
    'operation'=>'upsert','source'=>'app','service'=>'php','host'=>'app01','level'=>'error','message'=>'sample',
    'context'=>'{"safe":true}','trace_id'=>'trace1','request_id'=>'req1','tags'=>'["a"]','revision'=>3];
$a=AnalyticsReplicator::encode($r);
check($a['deleted']===0&&$a['revision']===3&&$a['context']['safe']===true&&$a['tags']===['a'],'upsert retains canonical fields and revision');
check($a['received_at']==='2026-11-18T13:10:01.000123Z','PostgreSQL timestamptz becomes canonical UTC');
$b=AnalyticsReplicator::encode(array_replace($r,['operation'=>'delete','revision'=>4,'context'=>'broken JSON','tags'=>'broken']));
check($b['deleted']===1&&$b['revision']===4&&$b['message']===''&&$b['tags']===[],'delete tombstone does not disclose removed event content');
check(str_contains(file_get_contents(dirname(__DIR__).'/migrations/clickhouse/0003_replicated_logs.sql'),'ReplacingMergeTree(revision,deleted)'),'ClickHouse mirror is versioned and tombstone-aware');
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0028_analytics_replication.sql');
$m=MigrationSql::parse($sql);
check($m['mode']==='transactional'&&count($m['statements'])>=10,'PostgreSQL migration is parsed transactionally with statement-level triggers');
check(str_contains($sql,'REFERENCING NEW TABLE AS new_rows')&&str_contains($sql,'REFERENCING OLD TABLE AS old_rows'),
    'insert/update/delete transitions are captured inside original transaction');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Analytics/AnalyticsReplicator.php'),'FOR UPDATE SKIP LOCKED'),'concurrent outbox workers use row locks');
echo "Replication unit: $n checks passed\n";
