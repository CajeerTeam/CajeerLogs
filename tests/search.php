<?php
declare(strict_types=1);

use Cajeer\Logs\Http\{HttpException,Request};
use Cajeer\Logs\Log\{LogQuery,LogExport};
use Cajeer\Logs\Search\PostgresFullTextSearchEngine;

$app=require dirname(__DIR__).'/core/bootstrap.php';
$app->boot(); $app->config()->set('app.env','test');
$count=0;
function check(bool $ok,string $name):void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$name); } ++$count; echo "PASS $name\n"; }
function invalid(callable $fn):bool { try { $fn(); } catch (HttpException $e) { return $e->status===422; } return false; }
$p='11111111-1111-4111-8111-111111111111'; $s='22222222-2222-4222-8222-222222222222'; $id='33333333-3333-4333-8333-333333333333';
$base=['project_id'=>$p];
$q=LogQuery::parse($base); check($q->limit===50&&$q->position()===null,'default page');
check(LogQuery::parse(['project_id'=>strtoupper('abcdefab-1234-4321-aaaa-123456789abc')])->filters['project_id']==='abcdefab-1234-4321-aaaa-123456789abc','UUID canonicalized');
check(LogQuery::parse($base+['limit'=>'100'])->limit===100,'max page');
foreach ([[],['project_id'=>'bad'],['project_id'=>[$p]],$base+['source_id'=>null],$base+['source_id'=>'bad'],$base+['source_id'=>[$s]],$base+['limit'=>'0'],$base+['limit'=>'101'],$base+['limit'=>'1e2'],$base+['limit'=>['1']],$base+['limit'=>'01'],$base+['q'=>str_repeat('x',513)],$base+['q'=>' '],$base+['q'=>"\0"],$base+['q'=>"\xff"],$base+['level'=>'fatal'],$base+['source'=>'bad source'],$base+['source'=>str_repeat('a',101)],$base+['trace_id'=>str_repeat('a',129)],$base+['context'=>'[]'],$base+['context'=>'null'],$base+['context'=>'{'],$base+['context'=>'{"a":"\u0000"}'],$base+['context'=>'{"a":1e999}'],$base+['context'=>str_repeat('x',4097)],$base+['unknown'=>'1'],$base+['from'=>'2026-02-30T00:00:00Z'],$base+['from'=>'2026-01-01'],$base+['from'=>'2026-01-01T00:00:00.1234567Z'],$base+['from'=>'2026-01-02T00:00:00Z','to'=>'2026-01-01T00:00:00Z']] as $i=>$bad) {
    check(invalid(fn()=>LogQuery::parse($bad)), 'reject invalid filter '.$i);
}
$q=LogQuery::parse($base+['source_id'=>$s,'source'=>'app','level'=>'error','service'=>'api','host'=>'host1','trace_id'=>'trace','request_id'=>'req','from'=>'2026-01-02T03:04:05.123456+03:00','to'=>'2026-01-03T00:00:00Z','q'=>"' OR 1=1 --",'tag'=>'db','context'=>'{"n":12345678901234567890}']);
check($q->filters['from']==='2026-01-02T00:04:05.123456Z','UTC microseconds');
[$where,$params]=PostgresFullTextSearchEngine::predicate($q);
check(str_contains($where,'project_id = CAST(:project_id AS uuid)')&&str_contains($where,'source_id = CAST(:source_id AS uuid)')&&str_contains($where,'source_id IS NOT NULL'),'mandatory SQL scope');
check(str_contains($where,"search_vector @@ websearch_to_tsquery('simple', :q)")&&str_contains($where,'context @> CAST(:context AS jsonb)')&&str_contains($where,'tags @> ARRAY[CAST(:tag AS text)]'),'real FTS and JSON/tag predicates');
check(!str_contains($where,'OR 1=1')&&$params['q']==="' OR 1=1 --"&&$params['context']==='{"n":12345678901234567890}','untrusted values bound, precision preserved');
check(str_contains($where,'occurred_at >= CAST(:from AS timestamptz)')&&str_contains($where,'occurred_at < CAST(:to AS timestamptz)'),'half-open time range');
$cursor=$q->next('2026-01-02T00:04:05.123456Z',$id);
$round=LogQuery::parse($q->filters+['cursor'=>$cursor,'limit'=>'2']);
check($round->position()===['t'=>'2026-01-02T00:04:05.123456Z','id'=>$id],'cursor round trip, variable page size');
check(invalid(fn()=>LogQuery::parse(array_replace($q->filters,['level'=>'info'])+['cursor'=>$cursor])->position()),'cursor cannot be reused with changed filters');
check(invalid(fn()=>LogQuery::parse($base+['cursor'=>$cursor])->position()),'cursor scope binding');
check(invalid(fn()=>LogQuery::parse($base+['cursor'=>'!!!!'])->position()),'malformed cursor');
check(LogQuery::parse($base)->scoped($s)->filters['source_id']===$s,'effective source applied');
check(invalid(fn()=>LogQuery::parse($base,'stats')),'stats require time bounds');
check(invalid(fn()=>LogQuery::parse($base+['from'=>'2026-01-01T00:00:00Z','to'=>'2026-03-01T00:00:00Z'],'stats')),'stats range capped');
$stats=$base+['from'=>'2026-01-01T00:00:00Z','to'=>'2026-02-01T00:00:00Z','interval'=>'day'];
check(LogQuery::parse($stats,'stats')->interval==='day','31-day stats range accepted');
check(invalid(fn()=>LogQuery::parse(array_replace($stats,['interval'=>'week']),'stats')),'reject arbitrary SQL interval');
$short=$base+['from'=>'2026-01-01T00:00:00Z','to'=>'2026-01-01T06:00:00Z','interval'=>'minute'];
check(LogQuery::parse($short,'stats')->interval==='minute','six-hour minute statistics accepted');
check(invalid(fn()=>LogQuery::parse(array_replace($short,['to'=>'2026-01-01T06:00:00.000001Z']),'stats')),'minute budget exact upper boundary');
check(LogQuery::parse(array_replace($short,['to'=>'2026-01-03T00:00:00Z','interval'=>'5min']),'stats')->interval==='5min','two-day five-minute statistics accepted');
check(invalid(fn()=>LogQuery::parse(array_replace($short,['to'=>'2026-01-03T00:00:00.000001Z','interval'=>'5min']),'stats')),'five-minute budget exact upper boundary');
check(invalid(fn()=>LogQuery::parse($base+['q'=>'x'],'detail')),'detail rejects irrelevant filters');
check(invalid(fn()=>LogQuery::parse($base+['format'=>'xml'],'export')),'invalid export format');
check(LogQuery::parse($base+['format'=>'csv'],'export')->format==='csv','CSV format accepted');
$row=['id'=>$id,'project_id'=>$p,'source_id'=>$s,'message'=>' =HYPERLINK("https://example.test")','service'=>"a,b\n\"c\"",'host'=>'+cmd','context'=>(object)['foo'=>'bar'],'tags'=>['a','b']];
$csv=LogExport::csv([$row]); $stream=fopen('php://temp','w+'); fwrite($stream,$csv); rewind($stream); $head=fgetcsv($stream,0,',','"',''); $cells=fgetcsv($stream,0,',','"',''); fclose($stream); $decoded=array_combine($head,$cells);
check($decoded['message']==="'".$row['message']&&$decoded['host']==="'+cmd",'CSV formula neutralization');
check($decoded['service']===$row['service']&&$decoded['context']==='{"foo":"bar"}'&&$decoded['tags']==='["a","b"]','CSV lossless quoted values and JSON');
check(substr_count(LogExport::csv([]),"\r\n")===1,'empty CSV header');
foreach (['/logs','/logs/stats','/logs/export','/logs/'.$id] as $path) { check($app->handle(new Request('GET','/api/v1'.$path,query:$base))->status===401,'protected '.$path); }
$app->config()->set('app.env','production'); $app->config()->set('app.url','https://logs.example.test');
check($app->handle(new Request('GET','/api/v1/logs',query:$base))->status===403,'TLS required');
$layout=['order'=>\Cajeer\Logs\Log\SavedView::COLUMNS,'visible'=>['timestamp','message'],'widths'=>['message'=>500],'density'=>'compact','wrap'=>true,'zone'=>'utc'];
$input=['name'=>'Test view','filters'=>['levels'=>'["warning","error"]','context'=>'{"n":12345678901234567890}'],'layout'=>$layout];
$saved=\Cajeer\Logs\Log\SavedView::validate($input,$p);
check($saved['filters']['levels']==='["error","warning"]'&&$saved['filters']['context']===$input['filters']['context']&&!isset($saved['filters']['project_id'])&&$saved['filters']['limit']==='50','saved view canonical search and exact JSON');
foreach ([['filters'=>['project_id'=>$p]],['filters'=>['cursor'=>'test']],['filters'=>['levels'=>'["fatal"]']],['layout'=>array_replace($layout,['visible'=>[]])],['layout'=>array_replace($layout,['widths'=>['message'=>801]])],['name'=>' ']] as $bad) check(invalid(fn()=>\Cajeer\Logs\Log\SavedView::validate(array_replace($input,$bad),$p)),'invalid saved view rejected');
echo "Log search: $count checks passed\n";
