<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationSql,SchemaInspector,MigrationRunner,DatabaseException};
use Cajeer\Logs\Console\Command\MigrateCommand;
$root=dirname(__DIR__); require $root.'/core/bootstrap.php';
$count=0;
function check(bool $ok,string $label):void { global $count; if(!$ok) throw new RuntimeException('FAIL '.$label); ++$count; echo "PASS $label\n"; }
function rejects(callable $f):bool { try{$f();}catch(DatabaseException){return true;}return false; }
foreach(glob($root.'/migrations/pgsql/*.sql') as $file) { $parsed=MigrationSql::parse(file_get_contents($file));check($parsed['mode']==='transactional'&&count($parsed['statements'])>0,'legacy SQL unchanged/parseable '.basename($file)); }
$p=MigrationSql::parse("-- comment ; COMMIT\nCREATE TABLE demo (v text DEFAULT 'one;two'); /* nested /* ; */ comment */ INSERT INTO demo VALUES ('it''s safe');");
check(count($p['statements'])===2&&$p['statements'][1]['words'][0]==='INSERT','comments and quoted semicolons');
$escaped=<<<'SQL'
SELECT E'one\'two;three'; SELECT $tag$commit;$tag$;
SQL;
check(count(MigrationSql::parse($escaped)['statements'])===2,'escaped and dollar quoted literals');
foreach(['BEGIN; CREATE TABLE a(x int)','CREATE TABLE a(x int); COMMIT','ROLLBACK','SET search_path TO other','CREATE INDEX CONCURRENTLY ix ON a(x)','DO $$ BEGIN COMMIT; END $$','CREATE TABLE a(x text DEFAULT \'broken)','/* unclosed','SELECT $$unclosed','-- comment only'] as $bad) check(rejects(fn()=>MigrationSql::parse($bad)),'reject unsupported/invalid SQL '.substr($bad,0,28));
$online=MigrationSql::parse("-- cajeer:concurrent-index\nCREATE INDEX CONCURRENTLY ix ON demo USING btree (id DESC, value);");
check($online['mode']==='concurrent-index'&&$online['index']['name']==='ix'&&$online['index']['table']==='demo','online index directive and identifiers');
foreach(['CREATE UNIQUE INDEX CONCURRENTLY ix ON demo(id)','CREATE INDEX CONCURRENTLY IF NOT EXISTS ix ON demo(id)','CREATE INDEX CONCURRENTLY ix ON other.demo(id)','CREATE INDEX CONCURRENTLY ix ON demo(id); DROP TABLE demo','CREATE INDEX CONCURRENTLY ix ON demo((lower(value)))'] as $bad) check(rejects(fn()=>MigrationSql::parse("-- cajeer:concurrent-index\n".$bad)),'online index rejects unsafe variant');
$backfill="-- cajeer:backfill\nWITH batch AS (SELECT id FROM demo WHERE value IS NULL ORDER BY id LIMIT 100) UPDATE demo SET value='done' FROM batch WHERE demo.id=batch.id AND demo.value IS NULL RETURNING demo.id;";
check(MigrationSql::parse($backfill)['mode']==='backfill','bounded update backfill parsed');
foreach(['UPDATE demo SET value=1 RETURNING id','WITH x AS (SELECT id FROM demo LIMIT 1) UPDATE demo SET value=1 RETURNING id','WITH x AS (UPDATE demo SET value=1 RETURNING id) SELECT id FROM x LIMIT 1','WITH x AS (SELECT id FROM demo) UPDATE demo SET value=1 WHERE id IN (SELECT id FROM x) RETURNING id','WITH x AS (SELECT id FROM demo LIMIT 1) UPDATE demo SET value=1 WHERE id IN (SELECT id FROM x)'] as $bad) check(rejects(fn()=>MigrationSql::parse("-- cajeer:backfill\n".$bad)),'backfill rejects unbounded or non-returning variant');
$expected=['column:t.a'=>'old','index:gone'=>'abc','relation:t'=>'table']; $actual=['column:t.a'=>'new','relation:t'=>'table','index:new'=>'def'];
check(SchemaInspector::differences($expected,$actual)===[['object'=>'column:t.a','change'=>'changed'],['object'=>'index:gone','change'=>'missing'],['object'=>'index:new','change'=>'unexpected']],'precise structural drift changes');
check(SchemaInspector::differences($expected,array_reverse($expected,true))===[],'catalog ordering does not produce drift');
$restoreBefore=[
    'constraint:audit_log.audit_chain_values'=>json_encode(['type'=>'c','definition'=>"CHECK (((chain_position > 0) AND ((result)::text = ANY ((ARRAY['success'::character varying, 'failure'::character varying])::text[]))))"],JSON_THROW_ON_ERROR),
    'constraint:users.users_role_valid'=>json_encode(['type'=>'c','definition'=>"CHECK (((role)::text = ANY ((ARRAY['administrator'::character varying, 'operator'::character varying])::text[])))"],JSON_THROW_ON_ERROR),
    'constraint:integration_webhooks.integration_webhooks_events_valid'=>json_encode(['type'=>'c','definition'=>"CHECK ((((cardinality(events) >= 1) AND (cardinality(events) <= 6)) AND (events <@ ARRAY['logs.ingested'::text, 'source.created'::text])))"],JSON_THROW_ON_ERROR),
    'index:log_events_error_analytics_idx'=>json_encode(['table'=>'log_events','predicate'=>"((source_id IS NOT NULL) AND ((level)::text = ANY ((ARRAY['error'::character varying, 'alert'::character varying])::text[])))"],JSON_THROW_ON_ERROR),
];
$restoreAfter=[
    'constraint:audit_log.audit_chain_values'=>json_encode(['type'=>'c','definition'=>"CHECK (((chain_position > 0) AND ((result)::text = ANY (ARRAY[('success'::character varying)::text, ('failure'::character varying)::text]))))"],JSON_THROW_ON_ERROR),
    'constraint:users.users_role_valid'=>json_encode(['type'=>'c','definition'=>"CHECK (((role)::text = ANY (ARRAY[('administrator'::character varying)::text, ('operator'::character varying)::text])))"],JSON_THROW_ON_ERROR),
    'constraint:integration_webhooks.integration_webhooks_events_valid'=>json_encode(['type'=>'c','definition'=>"CHECK (((cardinality(events) >= 1) AND (cardinality(events) <= 6) AND (events <@ ARRAY['logs.ingested'::text, 'source.created'::text])))"],JSON_THROW_ON_ERROR),
    'index:log_events_error_analytics_idx'=>json_encode(['table'=>'log_events','predicate'=>"((source_id IS NOT NULL) AND ((level)::text = ANY (ARRAY[('error'::character varying)::text, ('alert'::character varying)::text])))"],JSON_THROW_ON_ERROR),
];
check(SchemaInspector::differences($restoreBefore,$restoreAfter)===[], 'pg_restore alternate literal array casts and associative AND clauses have identical structure');
$invalidRestore=$restoreAfter;
$invalidRestore['constraint:users.users_role_valid']=str_replace('operator','viewer',$invalidRestore['constraint:users.users_role_valid']);
check(SchemaInspector::differences($restoreBefore,$invalidRestore)!==[], 'restore equivalence rejects changed allowed role literals');
$invalidRestore=$restoreAfter;
$invalidRestore['index:log_events_error_analytics_idx']=str_replace('"table":"log_events"','"table":"forged"',$invalidRestore['index:log_events_error_analytics_idx']);
check(SchemaInspector::differences($restoreBefore,$invalidRestore)!==[], 'restore equivalence still checks index catalog properties');
$unlisted=['constraint:unlisted.role'=>$restoreBefore['constraint:users.users_role_valid']];
check(SchemaInspector::differences($unlisted,['constraint:unlisted.role'=>$restoreAfter['constraint:users.users_role_valid']])!==[], 'unlisted expression changes remain strictly checked');
// A scratch-schema replay must compare equivalent trigger definitions without
// accepting changed function targets, unrelated namespaces, or column drift.
$replayMethod=(new ReflectionClass(MigrationRunner::class))->getMethod('normalizeReplayTriggers');
$replayRunner=(new ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
$one=['trigger:audit_log.guard'=>json_encode(['definition'=>'CREATE TRIGGER guard ON first.audit_log EXECUTE FUNCTION first.guard()','enabled'=>'A'],JSON_THROW_ON_ERROR),'column:audit_log.id'=>'uuid'];
$two=['trigger:audit_log.guard'=>json_encode(['definition'=>'CREATE TRIGGER guard ON second.audit_log EXECUTE FUNCTION second.guard()','enabled'=>'A'],JSON_THROW_ON_ERROR),'column:audit_log.id'=>'uuid'];
$normalizedOne=$replayMethod->invoke($replayRunner,$one,'first');
$normalizedTwo=$replayMethod->invoke($replayRunner,$two,'second');
check(SchemaInspector::differences($normalizedOne,$normalizedTwo)===[],'schema replay normalizes owning trigger namespace only');
$twoChanged=$two;$twoChanged['trigger:audit_log.guard']=json_encode(['definition'=>'CREATE TRIGGER guard ON second.audit_log EXECUTE FUNCTION third.guard()','enabled'=>'A'],JSON_THROW_ON_ERROR);
check(SchemaInspector::differences($normalizedOne,$replayMethod->invoke($replayRunner,$twoChanged,'second'))!==[],'schema replay rejects foreign function retargeting');
$twoChanged=$two;$twoChanged['column:audit_log.id']='text';
check(SchemaInspector::differences($normalizedOne,$replayMethod->invoke($replayRunner,$twoChanged,'second'))!==[],'schema replay still detects column drift');
$old=getenv('DB_MIGRATION_STATEMENT_TIMEOUT_MS'); $oldB=getenv('DB_MIGRATION_MAX_BACKFILL_BATCHES');
try{putenv('DB_MIGRATION_STATEMENT_TIMEOUT_MS=1500');putenv('DB_MIGRATION_MAX_BACKFILL_BATCHES=9');check(MigrateCommand::limits()===[1500,9],'explicit migration limits');putenv('DB_MIGRATION_MAX_BACKFILL_BATCHES=0');check(rejects(fn()=>MigrateCommand::limits()),'invalid backfill limit rejected');}finally{foreach(['DB_MIGRATION_STATEMENT_TIMEOUT_MS'=>$old,'DB_MIGRATION_MAX_BACKFILL_BATCHES'=>$oldB] as $k=>$v)putenv($v===false?$k:$k.'='.$v);}
check(rejects(fn()=>new MigrationRunner(new class extends PDO{public function __construct(){}},'/tmp','pg_catalog')),'system schema rejected before SQL');
echo "Migration engine: $count checks passed\n";
