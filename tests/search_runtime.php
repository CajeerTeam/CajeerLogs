<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{LogQuery,LogRepository,SearchRuntime};
use Cajeer\Logs\Http\{HttpException,Request,ErrorHandler,JsonResponse};
use Cajeer\Logs\Observability\IngestMetrics;
use Cajeer\Logs\Database\PerformanceInspector;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$n=0;
function check(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function fail(callable $f):HttpException {try{$f();}catch(HttpException $e){return $e;}throw new RuntimeException('Expected HTTP exception');}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$other='33333333-3333-4333-8333-333333333333';$base=['project_id'=>$p];$range=['from'=>'2026-01-01T00:00:00Z','to'=>'2026-01-02T00:00:00Z'];
$filters=['levels'=>'["warning","error","error"]','sources'=>'["worker","app"]','source_ids'=>json_encode([$other,$s]),'services'=>'["db,worker","api"]','hosts'=>'["host2","host1"]','trace_ids'=>'["t2","t1"]','request_ids'=>'["r2","r1"]','tags_all'=>'["prod","db"]','tags_any'=>'["queue","db"]','q'=>'database timeout','q_mode'=>'phrase','context_path'=>'["nested","a/b","0"]','context_equals'=>'123456789012345678901234567890'];
$q=LogQuery::parse($base+$range+$filters);[$sql,$params]=LogRepository::pageStatement($q);
check($q->filters['levels']==='["error","warning"]'&&$q->filters['sources']==='["app","worker"]','canonical ordered unique lists');
check(str_contains($sql,'level IN (CAST(:levels_0 AS text),CAST(:levels_1 AS text))')&&$params['levels_0']==='error','multi level bound SQL');
check(str_contains($sql,'source_id IN (SELECT id FROM sources WHERE project_id=CAST(:sources_project AS uuid)')&&$params['sources_project']===$p,'indexed source registry lookup scoped by project');
check(str_contains($sql,'source_id IN (CAST(:source_ids_0 AS uuid),CAST(:source_ids_1 AS uuid))'),'source UUID list uses typed values');
check($params['services_1']==='db,worker'&&str_contains($sql,'service IN ('),'commas in values are not separators');
check(str_contains($sql,'host IN (')&&str_contains($sql,'trace_id IN (')&&str_contains($sql,'request_id IN ('),'correlation and host chains');
check(str_contains($sql,'tags @> ARRAY[')&&str_contains($sql,'tags && ARRAY['),'tags all and any use GIN-supported operators');
check(str_contains($sql,"phraseto_tsquery('simple', :q)")&&$params['q']==='database timeout','phrase search');
check(str_contains($sql,'context #> ARRAY(SELECT jsonb_array_elements_text(CAST(:context_path AS jsonb)))')&&$params['context_equals']===$filters['context_equals'],'JSON path equality preserves decimal precision');
check(str_contains($sql,'source_id IS NOT NULL')&&str_contains($sql,'project_id = CAST(:project_id AS uuid)'),'mandatory tenant constraints');
check(str_contains(LogRepository::predicate($q->scoped($s))[0],'source_id = CAST(:source_id AS uuid)'),'source-scoped list intersection');
check(fail(fn()=>LogQuery::parse($base+['source_id'=>$other])->scoped($s))->status===403,'explicit foreign source forbidden');
$cursor=$q->next('2026-01-01T00:00:00Z',$other);$same=LogQuery::parse(array_replace($base+$range+$filters,['levels'=>'["error","warning"]'])+['cursor'=>$cursor]);
check($same->position()['id']===$other,'canonical list cursor survives reordering');
check(fail(fn()=>LogQuery::parse(array_replace($base+$range+$filters,['levels'=>'["error"]'])+['cursor'=>$cursor])->position())->status===422,'changed list rejects cursor');
check(LogQuery::parse($base+['q'=>'x','q_mode'=>'web'])->filters===LogQuery::parse($base+['q'=>'x'])->filters,'web default retains historical cursor fingerprint');
check(str_contains(LogRepository::predicate(LogQuery::parse($base+['q'=>'x','q_mode'=>'plain']))[0],"plainto_tsquery('simple', :q)"),'plain FTS');
check(str_contains(LogRepository::predicate(LogQuery::parse($base+$range+['context_path'=>'["nullable"]']))[0],'IS NOT NULL'),'path existence SQL');
check(LogRepository::predicate(LogQuery::parse($base+$range+['context_path'=>'["nullable"]','context_equals'=>'null']))[1]['context_equals']==='null','JSON null not SQL null');
foreach ([['levels'=>'[]'],['levels'=>'["fatal"]'],['sources'=>'["App"]'],['source_ids'=>'["bad"]'],['services'=>'{"0":"api"}'],['hosts'=>'[3]'],['tags_all'=>'["x",null]'],['tags_any'=>json_encode(array_fill(0,17,'x'))],['trace_ids'=>'[" "]'],['q_mode'=>'phrase'],['q'=>'x','q_mode'=>'raw'],['context_path'=>'["x"]'],['context_equals'=>'1'],['context'=>'{"x":1,"\\u0078":2}'],['context_path'=>'["x"]','context_equals'=>'[]']+$range,['context_path'=>'["x"]','context_equals'=>'{"x":1}']+$range,['context_path'=>'["x"]','from'=>'2026-01-01T00:00:00Z','to'=>'2026-03-01T00:00:00Z']] as $i=>$bad) check(fail(fn()=>LogQuery::parse($base+$bad))->status===422,'reject bad expanded filter '.$i);
$many=array_fill_keys(['levels','sources','services','hosts','trace_ids'],json_encode(array_fill(0,16,'info')));check(fail(fn()=>LogQuery::parse($base+$many))->status===422,'aggregate list count cap');
check(fail(fn()=>LogQuery::parse($base+['levels'=>'["error"]'],'detail'))->status===422,'detail does not accept list filters');
check(LogRepository::predicate(LogQuery::parse($base+$range+$filters,'stats'))===LogRepository::predicate(LogQuery::parse($base+$range+$filters,'export')),'same predicates for list, stats and export');
check(Request::searchQuery('q=hello+world&context_equals=%22a%2Bb%22')===['q'=>'hello world','context_equals'=>'"a+b"'],'strict URL decoding');
foreach (['q=a&q=b','q=a&%71=b','q%5B%5D=a','q.x=a','q=%GG','q','q=a&','q=a&&x=b'] as $bad) check(fail(fn()=>Request::searchQuery($bad))->status===422,'reject ambiguous raw query '.$bad);
check(fail(fn()=>SearchRuntime::validateText(3,'T',LogQuery::parse($base+['q'=>'-x'])))->status===422,'negative-only FTS requires bounded time');
SearchRuntime::validateText(3,'T',LogQuery::parse($base+$range+['q'=>'-x']));check(true,'bounded negative FTS');
check(fail(fn()=>SearchRuntime::validateText(129,"'a'",$q))->status===422,'PostgreSQL node complexity limit');
SearchRuntime::validateText(0,'',$q);check(true,'empty tsquery stays empty');
final class SearchStmt extends PDOStatement {public array $params=[];public function __construct(private array $row=[]){ }public function execute(?array $params=null):bool{$this->params=$params??[];return true;}public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;}public function closeCursor():bool{return true;}}
final class SearchPDO extends PDO {
    public bool $active=false;public array $calls=[];public array $tree=['nodes'=>1,'tree'=>"'database'"];public ?SearchStmt $stmt=null;
    public function __construct(){}public function inTransaction():bool{return $this->active;}public function beginTransaction():bool{$this->calls[]='BEGIN';return $this->active=true;}public function commit():bool{$this->calls[]='COMMIT';$this->active=false;return true;}public function rollBack():bool{$this->calls[]='ROLLBACK';$this->active=false;return true;}public function exec(string $statement):int|false{$this->calls[]=$statement;return 0;}public function prepare(string $query,array $options=[]):PDOStatement|false{$this->calls[]=$query;return $this->stmt=new SearchStmt($this->tree);}public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$this->calls[]=$query;return new SearchStmt();}
}
$pdo=new SearchPDO();$runtime=new SearchRuntime($pdo,5000);
check($runtime->run($q,fn(LogRepository $repo)=>'read')==='read'&&!$pdo->active&&end($pdo->calls)==='COMMIT','read transaction commits');
check(in_array('SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ ONLY',$pdo->calls,true)&&count(array_filter($pdo->calls,fn($v)=>str_starts_with($v,'SET LOCAL statement_timeout = ')))===2&&$pdo->stmt->params===['q'=>$filters['q']],'read-only budget and actual FTS preflight binding');
$pdo->tree=['nodes'=>2,'tree'=>'T'];$pdo->calls=[];check(fail(fn()=>$runtime->run(LogQuery::parse($base+['q'=>'-x']),fn()=>throw new RuntimeException('must not read')))->status===422&&end($pdo->calls)==='ROLLBACK','unindexable unbounded query stops before data read');
$pdo->calls=[];$err=fail(fn()=>$runtime->run(LogQuery::parse($base),function(){ $e=new PDOException('private database query');$e->errorInfo=['57014',0,'private database query'];throw $e;}));
check($err->error==='search_timeout'&&$err->status===503&&!$pdo->active&&end($pdo->calls)==='ROLLBACK','timeout rolls back and maps to safe error');
$r=ErrorHandler::response($err,'test');check($r->header('Retry-After')==='5'&&str_contains($r->body,'search_timeout')&&!str_contains($r->body,'private database query'),'timeout response sanitized');
$plan=PerformanceInspector::queries($p,null,$range+$filters);check($plan['filtered']===[$sql,$params],'filtered EXPLAIN uses identical SQL and values');
$dir=sys_get_temp_dir().'/search-metrics-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {$m=new IngestMetrics($dir,'search');$m->record('list',new JsonResponse(['items'=>[]]),.05);$m->record('stats',$r,.2);$render=$m->render();check(str_contains($render,'cajeerlogs_search_requests_total{mode="list",status="200"} 1')&&str_contains($render,'cajeerlogs_search_rejections_total{mode="stats",reason="search_timeout"} 1'),'search metrics include latency, result and timeout');check(!str_contains((new IngestMetrics($dir))->render(),'mode="list"'),'search metrics independent of ingest');check(IngestMetrics::searchMode('HEAD','/api/v1/logs/stats','/api/v1')==='stats'&&IngestMetrics::searchMode('GET','/api/v1/logs/'.$other,'/api/v1')==='detail','fixed cardinality route labels');}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
echo "Search Runtime: $n checks passed\n";
