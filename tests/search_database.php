<?php
declare(strict_types=1);

use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository,UserRepository};
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;

// Run only against an explicitly designated disposable PostgreSQL 17+ database.
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 and DB_* for an isolated PostgreSQL test database.\n"); exit(1); }
$root=dirname(__DIR__); $app=require $root.'/core/bootstrap.php'; $app->boot();
$base=$app->container()->get(PDO::class); $schema='cl_search_'.bin2hex(random_bytes(8));
$base->exec('CREATE SCHEMA "'.$schema.'"'); $tmp=sys_get_temp_dir().'/'.$schema; mkdir($tmp,0700);
$count=0;
function verify(bool $ok,string $name):void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$name); } ++$count; echo "PASS $name\n"; }
function api(string $method,string $path,array $query=[],array $headers=[],?array $body=null):Response {
    global $app;
    if ($body!==null) { $headers['Content-Type']='application/json'; }
    return $app->handle(new Request($method,'/api/v1'.$path,$headers,$query,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1'));
}
function data(Response $r):array { return json_decode($r->body,true,64,JSON_THROW_ON_ERROR); }
function bearer(string $secret):array { return ['Authorization'=>'Bearer '.$secret]; }
function get(string $path,array $query,array $headers,int $status=200):Response {
    $r=api('GET',$path,$query,$headers); verify($r->status===$status,'GET '.$path.' => '.$status.' (got '.$r->status.')'); return $r;
}
function ingest(array $event,string $secret):string {
    $r=api('POST','/ingest',[],bearer($secret),$event); verify($r->status===201,'ingest commits event'); return data($r)['event_ids'][0];
}
function login(string $email,string $password):array {
    $r=api('POST','/auth/login',[],[],['email'=>$email,'password'=>$password]); verify($r->status===200,'session login '.$email);
    return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0]];
}
try {
    $app=new Application($root); $app->boot(); $app->config()->set('database.schema',$schema); $app->config()->set('app.env','test'); $app->config()->set('app.url','http://localhost:8080'); $app->config()->set('api.rate_limit',600); $app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);
    foreach (['0001_initial_schema.sql','0002_projects_sources.sql','0003_identity_access.sql'] as $file) { copy($root.'/migrations/pgsql/'.$file,$tmp.'/'.$file); }
    $old=new MigrationRunner($pdo,$tmp,$schema); $old->migrate();
    $legacy=$pdo->query("INSERT INTO log_events(occurred_at,source,level,message) VALUES(now(),'legacy','info','historic unscoped') RETURNING id")->fetchColumn();
    copy($root.'/migrations/pgsql/0004_log_ingestion.sql',$tmp.'/0004_log_ingestion.sql'); $old->migrate();
    $projects=new ProjectRepository($pdo); $sources=new SourceRepository($pdo); $tokens=new TokenRepository($pdo); $users=new UserRepository($pdo);
    $project=$projects->create('First','first'); $foreign=$projects->create('Second','second');
    $source=$sources->create($project,'App','app'); $other=$sources->create($project,'Worker','worker'); $foreignSource=$sources->create($foreign,'App','app');
    $writer=$tokens->create('writer',['logs:write'],$project)['token']; $foreignWriter=$tokens->create('foreign writer',['logs:write'],$foreign)['token'];
    $reader=$tokens->create('reader',['logs:read'],$project); $scoped=$tokens->create('scoped reader',['logs:read'],$project,$source); $foreignReader=$tokens->create('foreign reader',['logs:read'],$foreign);
    $readerHeaders=bearer($reader['token']); $sourceHeaders=bearer($scoped['token']);
    $common=['source'=>'app','timestamp'=>'2026-01-02T00:00:00.123456Z','level'=>'error','message'=>'database connection failed','service'=>'api','host'=>'host1','trace_id'=>'trace-a','request_id'=>'req-a','context'=>(object)['code'=>500,'nested'=>(object)['region'=>'eu'],'empty'=>(object)[]],'tags'=>['db','prod']];
    $a=ingest($common,$writer); $b=ingest(array_replace($common,['message'=>'database query failed','trace_id'=>'trace-b','request_id'=>'req-b']),$writer);
    $c=ingest(array_replace($common,['timestamp'=>'2026-01-02T01:00:00Z','source'=>'worker','level'=>'warning','message'=>'queue retry','trace_id'=>'trace-c','request_id'=>'req-c','tags'=>['queue'],'context'=>(object)['code'=>429]]),$writer);
    $d=ingest(array_replace($common,['timestamp'=>'2026-01-03T00:00:00Z','level'=>'info','message'=>'=HYPERLINK("https://example.test")','trace_id'=>'trace-d','request_id'=>'req-d','tags'=>[],'context'=>(object)[]]),$writer);
    $hidden=ingest($common,$foreignWriter);
    $runner=$app->container()->get(MigrationRunner::class);
    verify($runner->migrate()===['0005_log_search.sql','0006_sdk_integrations.sql','0007_queue_lifecycle.sql']&&$runner->migrate()===[],'real 0.0.4 to 0.0.5 migration and repeat');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===6,'migration preserves stored and legacy events');
    verify($app->handle(new Request('GET','/ready'))->status===200,'readiness after migration');
    $query=['project_id'=>$project];
    $all=data(get('/logs',$query,$readerHeaders)); $ids=array_column($all['items'],'id');
    $ties=[$a,$b]; rsort($ties,SORT_STRING);
    verify($ids===[$d,$c,...$ties]&&$all['next_cursor']===null,'deterministic timestamp/UUID sort and project isolation');
    $page=data(get('/logs',$query+['limit'=>'1'],$readerHeaders)); $seen=array_column($page['items'],'id');
    while ($page['next_cursor']!==null) { $page=data(get('/logs',$query+['limit'=>'1','cursor'=>$page['next_cursor']],$readerHeaders)); $seen=array_merge($seen,array_column($page['items'],'id')); if (count($seen)>4) { throw new RuntimeException('Pagination loop'); } }
    verify($seen===$ids,'keyset pagination no duplicates or skips, including equal timestamps');
    $page=data(get('/logs',$query+['limit'=>'2'],$readerHeaders)); get('/logs',$query+['cursor'=>$page['next_cursor'],'level'=>'info'],$readerHeaders,422);
    $scopedData=data(get('/logs',$query,$sourceHeaders)); verify(array_column($scopedData['items'],'id')===[$d,...$ties],'source token automatically scoped');
    get('/logs',$query+['source_id'=>$other],$sourceHeaders,403); get('/logs',['project_id'=>$foreign],$readerHeaders,403); get('/logs',$query,bearer($writer),403);
    verify(array_column(data(get('/logs',['project_id'=>$foreign],bearer($foreignReader['token'])))['items'],'id')===[$hidden],'other project independent');
    $detail=data(get('/logs/'.$a,$query,$readerHeaders))['data'];
    verify($detail['id']===$a&&$detail['source_id']===$source&&$detail['project_id']===$project&&$detail['timestamp']==='2026-01-02T00:00:00.123456Z'&&$detail['tags']===['db','prod']&&$detail['context']['nested']['region']==='eu','complete stored event with UTC and JSON');
    get('/logs/'.$hidden,$query,$readerHeaders,404); get('/logs/'.$c,$query,$sourceHeaders,404); get('/logs/'.$legacy,$query,$readerHeaders,404);
    verify(array_column(data(get('/logs',$query+['q'=>'"database connection"'],$readerHeaders))['items'],'id')===[$a],'PostgreSQL phrase FTS');
    verify(count(data(get('/logs',$query+['q'=>'database OR queue'],$readerHeaders))['items'])===3,'FTS OR syntax');
    verify(count(data(get('/logs',$query+['q'=>'definitelynomatch'],$readerHeaders))['items'])===0,'empty result');
    verify(array_column(data(get('/logs',$query+['source'=>'worker'],$readerHeaders))['items'],'id')===[$c],'source slug filter');
    verify(array_column(data(get('/logs',$query+['source_id'=>$other],$readerHeaders))['items'],'id')===[$c],'source UUID filter');
    verify(array_column(data(get('/logs',$query+['level'=>'error','service'=>'api','host'=>'host1','tag'=>'db','context'=>'{"nested":{"region":"eu"}}'],$readerHeaders))['items'],'id')===$ties,'combined metadata, tag and nested JSONB filters');
    verify(array_column(data(get('/logs',$query+['trace_id'=>'trace-b','request_id'=>'req-b'],$readerHeaders))['items'],'id')===[$b],'trace and request filters');
    verify(array_column(data(get('/logs',$query+['from'=>'2026-01-02T00:00:00.123456Z','to'=>'2026-01-02T01:00:00Z'],$readerHeaders))['items'],'id')===$ties,'inclusive lower and exclusive upper bounds');
    verify(data(get('/logs',$query+['service'=>"api' OR 1=1 --"],$readerHeaders))['items']===[],'SQL injection string treated as value');
    $range=$query+['from'=>'2026-01-02T00:00:00Z','to'=>'2026-01-03T00:00:00Z'];
    $stats=data(get('/logs/stats',$range,$readerHeaders));
    verify($stats['total']===3&&$stats['by_level']['error']===2&&$stats['by_level']['warning']===1&&$stats['by_level']['info']===0&&$stats['by_source'][0]['source_id']===$source&&$stats['by_source'][0]['count']===2&&!$stats['sources_truncated'],'statistics total, levels and sources');
    verify($stats['buckets']===[['timestamp'=>'2026-01-02T00:00:00.000000Z','count'=>2],['timestamp'=>'2026-01-02T01:00:00.000000Z','count'=>1]],'hourly UTC aggregation');
    $stats=data(get('/logs/stats',$range+['interval'=>'day','level'=>'error'],$sourceHeaders)); verify($stats['total']===2&&count($stats['buckets'])===1&&$stats['buckets'][0]['count']===2,'daily filtered source stats');
    $stats=data(get('/logs/stats',$range+['q'=>'definitelynomatch'],$readerHeaders)); verify($stats['total']===0&&$stats['buckets']===[]&&$stats['by_source']===[],'empty aggregate');
    $export=get('/logs/export',$query+['limit'=>'1'],$readerHeaders); verify(str_contains($export->header('Content-Disposition'),'cajeerlogs.json')&&$export->header('X-Next-Cursor')===data($export)['next_cursor'],'JSON attachment and continuation');
    $csv=get('/logs/export',$query+['format'=>'csv','limit'=>'1'],$readerHeaders); verify(str_starts_with($csv->header('Content-Type'),'text/csv')&&$csv->header('X-Next-Cursor')!==null&&str_contains($csv->body,"'=HYPERLINK"),'CSV export and spreadsheet injection protection');
    $next=data(get('/logs/export',$query+['cursor'=>$csv->header('X-Next-Cursor'),'limit'=>'1'],$readerHeaders)); verify($next['items'][0]['id']===$c,'CSV cursor reusable for JSON next page');
    $password='viewer-test-password-123'; $viewer=$users->create('viewer@example.test',$password,'viewer');
    $admin=$users->create('admin@example.test','admin-test-password-123','administrator');
    $session=login('viewer@example.test',$password); $adminSession=login('admin@example.test','admin-test-password-123');
    get('/logs',$query,$session,403); $stmt=$pdo->prepare('INSERT INTO project_members(project_id,user_id) VALUES(:p,:u)'); $stmt->execute(['p'=>$project,'u'=>$viewer]);
    verify(count(data(get('/logs',$query,$session))['items'])===4,'viewer project membership grants read');
    get('/logs',['project_id'=>$foreign],$session,403); verify(count(data(get('/logs',['project_id'=>$foreign],$adminSession))['items'])===1,'admin may select any project explicitly');
    $stmt=$pdo->prepare('DELETE FROM project_members WHERE project_id=:p AND user_id=:u'); $stmt->execute(['p'=>$project,'u'=>$viewer]); get('/logs',$query,$session,403);
    $tokens->revoke($scoped['id']); get('/logs',$query,$sourceHeaders,401);
    // Real HTTP across a new PHP process, not merely direct dispatch.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); if ($socket===false) { throw new RuntimeException('Cannot allocate test port.'); }
    $address=stream_socket_get_name($socket,false); fclose($socket); $log=$tmp.'/http.log';
    $env=array_replace(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','APP_URL'=>'http://'.$address,'DB_SCHEMA'=>$schema,'API_RATE_LIMIT_PER_MINUTE'=>'600']);
    $process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); } fclose($pipes[0]);
    try {
        $ready=false; for ($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if ($probe!==false) { fclose($probe); $ready=true; break; } usleep(50000); } verify($ready,'network HTTP server running');
        $ctx=stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$reader['token']."\r\n",'ignore_errors'=>true,'timeout'=>15]]);
        $result=file_get_contents('http://'.$address.'/api/v1/logs?'.http_build_query($query+['q'=>'database','limit'=>'1']),false,$ctx);
        verify($result!==false&&str_contains($http_response_header[0]??'',' 200 ')&&count(json_decode($result,true,32,JSON_THROW_ON_ERROR)['items'])===1,'network search reads committed PostgreSQL event');
    } finally { proc_terminate($process); proc_close($process); if (is_file($log)) { unlink($log); } }
    echo "Search PostgreSQL: $count checks passed\n";
} finally {
    if (isset($pdo)&&$pdo->inTransaction()) { $pdo->rollBack(); }
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    foreach (glob($tmp.'/*.sql') as $file) { unlink($file); } rmdir($tmp);
}
