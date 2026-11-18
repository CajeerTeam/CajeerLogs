<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Use an isolated PostgreSQL 17+ database and CAJEER_TEST_DATABASE=1.\n");exit(78);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_advsearch_'.bin2hex(random_bytes(7));$admin->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function verifySearch(bool $condition,string $why):void{global $n;if(!$condition)throw new RuntimeException('FAIL '.$why);++$n;echo "PASS $why\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('api.rate_limit',2000);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('Search A','search-a');$other=$projects->create('Search B','search-b');
    $sources=new SourceRepository($pdo);$a=$sources->create($p,'API','api');$b=$sources->create($p,'Worker','worker');$c=$sources->create($other,'API','api');
    $tokens=new TokenRepository($pdo);$write=$tokens->create('writer',['logs:write'],$p)['id'];$foreignWrite=$tokens->create('writer',['logs:write'],$other)['id'];
    $read=$tokens->create('reader',['logs:read'],$p)['token'];$scoped=$tokens->create('source reader',['logs:read'],$p,$a)['token'];$foreignRead=$tokens->create('reader',['logs:read'],$other)['token'];
    $repo=new LogRepository($pdo);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $at=$now->modify('-2 hours')->format('Y-m-d\TH:i:s\Z');$from=$now->modify('-1 day')->format('Y-m-d\TH:i:s\Z');$to=$now->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');
    $put=static function(string $project,string $source,string $sourceName,string $token,string $level,string $trace,string $request,string $service,string $host)use($pdo,$repo,$at):string {
        $payload=['timestamp'=>$at,'source'=>$sourceName,'level'=>$level,'message'=>'Correlated real event','service'=>$service,'host'=>$host,'trace_id'=>$trace,'request_id'=>$request];
        $events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode($payload,JSON_THROW_ON_ERROR)));
        return (new TransactionManager($pdo))->run(fn()=>$repo->storeBatch($events,$project,[$sourceName=>$source],$token))[0];
    };
    $anchor=$put($p,$a,'api',$write,'error','trace-A','req-A','frontend','host-1');
    $matchingTrace=$put($p,$b,'worker',$write,'warning','trace-A','req-B','worker','host-2');
    $matchingRequest=$put($p,$a,'api',$write,'info','trace-B','req-A','frontend','host-1');
    $unrelated=$put($p,$b,'worker',$write,'info','trace-C','req-C','worker','host-2');
    $foreign=$put($other,$c,'api',$foreignWrite,'error','trace-A','req-A','frontend','host-1');
    $run=static function(string $project,string $token,array $query)use($app):array {
        $r=$app->handle(new Request('GET','/api/v1/logs',['Authorization'=>'Bearer '.$token],['project_id'=>$project]+$query,'','127.0.0.1'));
        return [$r->status,json_decode($r->body,true,64,JSON_THROW_ON_ERROR)];
    };
    $scope=['from'=>$from,'to'=>$to,'related_event'=>$anchor];
    [$status,$result]=$run($p,$read,$scope);verifySearch($status===200&&array_column($result['items'],'id')!==[]&&count($result['items'])===3,'both correlation fields, cross-source');
    verifySearch(!in_array($foreign,array_column($result['items'],'id'),true)&&!in_array($unrelated,array_column($result['items'],'id'),true),'foreign and unrelated excluded');
    [$status,$result]=$run($p,$read,$scope+['relation'=>'trace']);verifySearch($status===200&&count($result['items'])===2&&in_array($matchingTrace,array_column($result['items'],'id'),true),'trace-only correlation');
    [$status,$result]=$run($p,$read,$scope+['relation'=>'request']);verifySearch($status===200&&count($result['items'])===2&&in_array($matchingRequest,array_column($result['items'],'id'),true),'request-only correlation');
    [$status,$result]=$run($p,$scoped,$scope);verifySearch($status===200&&count($result['items'])===2&&!in_array($matchingTrace,array_column($result['items'],'id'),true),'source-scoped correlation');
    [$status,$result]=$run($p,$read,['from'=>$from,'to'=>$to,'related_event'=>$foreign]);verifySearch($status===200&&$result['items']===[],'foreign anchor never leaks project');
    [$status,$result]=$run($other,$foreignRead,['from'=>$from,'to'=>$to,'related_event'=>$anchor]);verifySearch($status===200&&$result['items']===[],'foreign project cannot correlate local anchor');
    $facet=static function(string $token,array $filters)use($app,$p):array {
        $r=$app->handle(new Request('GET','/api/v1/logs/facets',['Authorization'=>'Bearer '.$token],['project_id'=>$p]+$filters,'','127.0.0.1'));
        return [$r->status,json_decode($r->body,true,64,JSON_THROW_ON_ERROR)];
    };
    [$status,$result]=$facet($read,['from'=>$from,'to'=>$to]);verifySearch($status===200&&$result['total']===4&&count($result['sources'])===2&&count($result['services'])===2,'facets count entire scoped set');
    verifySearch(array_sum(array_column($result['levels'],'count'))===4&&array_sum(array_column($result['hosts'],'count'))===4,'exact facet counts');
    [$status,$result]=$facet($scoped,['from'=>$from,'to'=>$to]);verifySearch($status===200&&$result['total']===2&&count($result['sources'])===1,'facets source-scoped');
    [$status,$result]=$facet($read,['from'=>$from,'to'=>$to,'level'=>'error']);verifySearch($status===200&&$result['total']===1&&$result['levels'][0]['value']==='error','facets respect filters');
    $pdo->prepare('DELETE FROM log_events WHERE id=CAST(:id AS uuid)')->execute(['id'=>$matchingRequest]);
    [$status,$result]=$facet($read,['from'=>$from,'to'=>$to]);verifySearch($status===200&&$result['total']===3,'facets reflect deletes immediately');
    echo "Advanced Search PostgreSQL: $n checks passed\n";
} finally {$admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
