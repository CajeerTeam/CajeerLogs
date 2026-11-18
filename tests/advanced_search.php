<?php
declare(strict_types=1);
use Cajeer\Logs\Database\MigrationSql;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Log\{LogQuery,LogRepository};
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function proof(bool $ok,string $why):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$why);++$n;}
function invalid(callable $fn):bool {try {$fn();}catch(HttpException $e){return $e->status===422;}return false;}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$event='33333333-3333-4333-8333-333333333333';
$range=['project_id'=>$p,'from'=>'2026-11-12T00:00:00Z','to'=>'2026-11-18T00:00:00Z'];
$q=LogQuery::parse($range+['related_event'=>$event],'list');
proof(($q->filters['related_event']??null)===$event&&!isset($q->filters['relation']),'default either correlation');
[$sql,$params]=LogRepository::predicate($q);
proof(str_contains($sql,'EXISTS (SELECT 1 FROM log_events anchor')&&str_contains($sql,'anchor.project_id=CAST(:related_project AS uuid)')&&str_contains($sql,'log_events.trace_id=anchor.trace_id')&&str_contains($sql,'log_events.request_id=anchor.request_id'),'project-scoped correlated SQL');
proof($params['related_event']===$event&&$params['related_project']===$p&&!str_contains($sql,$event),'UUID bound, not interpolated');
[$sql,$bindings]=LogRepository::predicate(LogQuery::parse($range+['related_event'=>$event,'relation'=>'trace'],'list')->scoped($s));
proof(str_contains($sql,'anchor.source_id=CAST(:related_source AS uuid)')&&!str_contains($sql,'log_events.request_id=anchor.request_id')&&$bindings['related_source']===$s,'source-bound correlation anchor');
foreach (['request','trace','either'] as $kind) proof(LogQuery::parse($range+['related_event'=>$event,'relation'=>$kind])->filters['related_event']===$event,'valid correlation '.$kind);
foreach ([$range+['relation'=>'either'],$range+['related_event'=>'bad'],$range+['related_event'=>$event,'relation'=>'bad'],['project_id'=>$p,'related_event'=>$event],['project_id'=>$p,'related_event'=>$event,'from'=>'2026-11-01T00:00:00Z','to'=>'2026-11-18T00:00:00Z']] as $bad)proof(invalid(fn()=>LogQuery::parse($bad)),'reject unsafe correlation');
proof(LogQuery::parse($range,'facets')->filters['project_id']===$p,'bounded faceted query');
foreach ([['project_id'=>$p],['project_id'=>$p,'from'=>'2026-11-01T00:00:00Z','to'=>'2026-11-18T00:00:00Z'],$range+['cursor'=>'x'],$range+['limit'=>'100']] as $bad)proof(invalid(fn()=>LogQuery::parse($bad,'facets')),'reject unbounded or pagination for facets');
$script=MigrationSql::parse(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0032_advanced_search.sql'));
proof($script['mode']==='transactional'&&count($script['statements'])===4,'actual correlation and metadata indexes');
proof(str_contains($script['statements'][0]['sql'],'project_id,trace_id,occurred_at')&&str_contains($script['statements'][1]['sql'],'project_id,request_id,occurred_at'),'indexed correlation keys');
proof(str_contains($script['statements'][2]['sql'],'project_id,service,occurred_at')&&str_contains($script['statements'][3]['sql'],'project_id,host,occurred_at'),'indexed metadata keys');
echo "Advanced search: $n checks passed\n";
