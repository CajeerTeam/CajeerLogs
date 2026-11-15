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
    global $pdo;
    // Seed the historical 0004 schema using the same validated repository write, not
    // the current HTTP runtime (which correctly refuses pending migrations).
    $events=(new \Cajeer\Logs\Log\IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode($event,JSON_THROW_ON_ERROR)));
    $repo=new \Cajeer\Logs\Log\LogRepository($pdo);
    return (new \Cajeer\Logs\Database\TransactionManager($pdo))->run(function () use ($pdo,$repo,$events,$secret) {
        $stmt=$pdo->prepare('SELECT id,project_id,source_id FROM api_tokens WHERE token_hash=:hash AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now()) FOR UPDATE');
        $stmt->execute(['hash'=>hash('sha256',$secret)]); $token=$stmt->fetch();
        if (!$token) throw new RuntimeException('Historical seed token unavailable');
        $sources=$repo->resolveSources($events,$token['project_id'],$token['source_id']);
        return $repo->storeBatch($events,$token['project_id'],$sources,$token['id'])[0];
    });
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
    verify($runner->migrate()===['0005_log_search.sql','0006_sdk_integrations.sql','0007_queue_lifecycle.sql','0008_security_operations.sql','0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql']&&$runner->migrate()===[],'real 0.0.4 to 0.0.5 migration and repeat');
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
    // Real SQL execution of the expanded filters, including typed JSONB values.
    $multi=$range+['levels'=>'["error","warning"]','sources'=>'["app","worker"]','services'=>'["api","other"]','hosts'=>'["host1"]','trace_ids'=>'["trace-a","trace-b","trace-c"]','request_ids'=>'["req-a","req-b","req-c"]','tags_any'=>'["db","queue"]'];
    verify(array_column(data(get('/logs',$multi,$readerHeaders))['items'],'id')===[$c,...$ties],'compound multi-value filters execute with correct AND/OR semantics');
    verify(array_column(data(get('/logs',$multi+['tags_all'=>'["db","prod"]'],$readerHeaders))['items'],'id')===$ties,'all tags containment');
    verify(array_column(data(get('/logs',$multi,$sourceHeaders))['items'],'id')===$ties,'multi-source cannot broaden bound credential');
    verify(data(get('/logs',$query+['source_ids'=>json_encode([$foreignSource])],$readerHeaders))['items']===[],'foreign UUID list cannot leak rows');
    verify(array_column(data(get('/logs',$query+['source_ids'=>json_encode([$other,$source,$foreignSource])],$sourceHeaders))['items'],'id')===[$d,...$ties],'source UUID list intersected with credential');
    verify(array_column(data(get('/logs',$query+['q'=>'database connection','q_mode'=>'phrase'],$readerHeaders))['items'],'id')===[$a],'phrase mode SQL');
    verify(array_column(data(get('/logs',$query+['q'=>'database failed','q_mode'=>'plain'],$readerHeaders))['items'],'id')===$ties,'plain all-words mode');
    get('/logs',$query+['q'=>'-database'],$readerHeaders,422);
    verify(array_column(data(get('/logs',$range+['q'=>'-database'],$readerHeaders))['items'],'id')===[$c],'bounded negative FTS');
    $path=$range+['context_path'=>'["nested","region"]','context_equals'=>'"eu"'];
    verify(array_column(data(get('/logs',$path,$readerHeaders))['items'],'id')===$ties,'exact nested JSONB scalar comparison');
    $patch=$pdo->prepare('UPDATE log_events SET context=context || CAST(:patch AS jsonb) WHERE id=:id');
    $patch->execute(['patch'=>'{"exact":12345678901234567890,"nullable":null,"list":[{"region":"eu"}]}','id'=>$a]);
    verify(array_column(data(get('/logs',$range+['context_path'=>'["exact"]','context_equals'=>'12345678901234567890'],$readerHeaders))['items'],'id')===[$a],'exact high precision JSONB number');
    verify(data(get('/logs',$range+['context_path'=>'["exact"]','context_equals'=>'"12345678901234567890"'],$readerHeaders))['items']===[],'JSON number differs from JSON string');
    $decimal='12345678901234567890.12345678901234567890';
    $patch->execute(['patch'=>'{"decimal":'.$decimal.'}','id'=>$a]);
    $precisePage=data(get('/logs',$range+['context_path'=>'["decimal"]','context_equals'=>$decimal],$readerHeaders));
    verify(count($precisePage['items'])===1&&$precisePage['items'][0]['context']['decimal']===$decimal,'SQL exact decimal and list serialization agree');
    $detailResponse=get('/logs/'.$a,$query,$readerHeaders);
    verify(data($detailResponse)['data']['context']['decimal']===$decimal&&$detailResponse->header('Cache-Control')==='no-store','detail keeps precision and privacy headers');
    $preciseExport=get('/logs/export',$range+['context_path'=>'["decimal"]','context_equals'=>$decimal,'format'=>'csv'],$readerHeaders);
    verify(str_contains($preciseExport->body,$decimal),'CSV retains exact historical decimal');

    verify(array_column(data(get('/logs',$range+['context_path'=>'["nullable"]','context_equals'=>'null'],$readerHeaders))['items'],'id')===[$a],'JSON null differs from missing path');
    verify(array_column(data(get('/logs',$range+['context_path'=>'["nullable"]'],$readerHeaders))['items'],'id')===[$a],'path existence includes JSON null');
    verify(array_column(data(get('/logs',$range+['context_path'=>'["list","0","region"]','context_equals'=>'"eu"'],$readerHeaders))['items'],'id')===[$a],'array index traversal');
    verify(data(get('/logs/stats',$path,$readerHeaders))['total']===2,'expanded statistics predicate');
    verify(array_column(data(get('/logs/export',$path,$readerHeaders))['items'],'id')===$ties,'expanded export predicate');
    $filteredPage=data(get('/logs',$multi+['limit'=>'1'],$readerHeaders));
    verify(array_column(data(get('/logs',$multi+['cursor'=>$filteredPage['next_cursor'],'limit'=>'2'],$readerHeaders))['items'],'id')===$ties,'expanded keyset continuation');
    $plan=(new \Cajeer\Logs\Database\PerformanceInspector($pdo))->explain($project,null,true,['levels'=>'["error","warning"]','sources'=>'["app","worker"]','q'=>'database']);
    verify(isset($plan['plans']['filtered'][0]['Plan']['Actual Rows']),'real EXPLAIN ANALYZE for expanded query');
    $beforeTimeout=$pdo->query('SHOW statement_timeout')->fetchColumn();
    try {
        (new \Cajeer\Logs\Log\SearchRuntime($pdo,100))->run(\Cajeer\Logs\Log\LogQuery::parse($query),function () use ($pdo) { $pdo->query('SELECT pg_sleep(0.5)')->closeCursor(); });
        throw new RuntimeException('Expected statement timeout');
    } catch (\Cajeer\Logs\Http\HttpException $e) { verify($e->status===503&&$e->error==='search_timeout','real PostgreSQL statement cancellation'); }
    verify(!$pdo->inTransaction()&&$pdo->query('SHOW statement_timeout')->fetchColumn()===$beforeTimeout,'read timeout rollback restores session setting');
    verify(count(data(get('/logs',$query,$readerHeaders))['items'])===4,'read works after timeout');
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
    // Signed navigation is bound to the actual authenticated principal, and the
    // first snapshot drains normal in-flight writes before fixing received_at.
    $snapshot=$query+['consistency'=>'snapshot','limit'=>'2'];
    $first=data(get('/logs',$snapshot,$readerHeaders));
    verify($first['snapshot_at']!==null&&$first['prev_cursor']===null&&array_column($first['items'],'id')===[$d,$c],'first signed snapshot page');
    $nextPage=data(get('/logs',$snapshot+['cursor'=>$first['next_cursor']],$readerHeaders));
    verify(array_column($nextPage['items'],'id')===$ties&&$nextPage['next_cursor']===null&&$nextPage['snapshot_at']===$first['snapshot_at'],'snapshot continuation preserves timestamp ties and cutoff');
    $back=data(get('/logs',$snapshot+['cursor'=>$nextPage['prev_cursor']],$readerHeaders));
    verify(array_column($back['items'],'id')===[$d,$c]&&$back['page_digest']===$first['page_digest']&&$back['prev_cursor']===null,'reverse keyset returns first page in descending order');
    $secondReader=$tokens->create('second reader',['logs:read'],$project);
    get('/logs',$snapshot+['cursor'=>$first['next_cursor']],bearer($secondReader['token']),422);
    $tampered=substr($first['next_cursor'],0,-1).(substr($first['next_cursor'],-1)==='A'?'B':'A');
    get('/logs',$snapshot+['cursor'=>$tampered],$readerHeaders,422);
    get('/logs',$query+['cursor'=>$first['next_cursor'],'limit'=>'2'],$readerHeaders,422);
    $legacyCursor=\Cajeer\Logs\Log\LogQuery::parse($query)->next($first['items'][0]['timestamp'],$d);
    get('/logs',$query+['cursor'=>$legacyCursor],$readerHeaders,422);
    $late=api('POST','/ingest',[],bearer($writer),array_replace($common,['timestamp'=>'2026-01-02T00:30:00Z','message'=>'backdated after snapshot']));
    verify($late->status===201,'new backdated event accepted after snapshot');$lateId=data($late)['event_ids'][0];
    verify(!in_array($lateId,array_column(data(get('/logs',$snapshot+['cursor'=>$first['next_cursor']],$readerHeaders))['items'],'id'),true),'late arrival excluded from snapshot');
    verify(in_array($lateId,array_column(data(get('/logs',$query,$readerHeaders))['items'],'id'),true),'late arrival visible in live search');
    $verifiedExport=get('/logs/export',$snapshot+['cursor'=>$first['page_cursor'],'format'=>'csv'],$readerHeaders+['If-Match'=>'"'.$first['page_digest'].'"']);
    verify($verifiedExport->header('X-Page-Digest')===$first['page_digest']&&$verifiedExport->header('X-Page-Cursor')===$first['page_cursor']&&str_contains($verifiedExport->body,$d),'verified CSV same page and continuation');
    $pdo->prepare('UPDATE log_events SET message=:message WHERE id=:id')->execute(['message'=>'changed after display','id'=>$d]);
    get('/logs/export',$snapshot+['cursor'=>$first['page_cursor'],'format'=>'csv'],$readerHeaders+['If-Match'=>'"'.$first['page_digest'].'"'],412);
    // Actual concurrent PostgreSQL session holding the writer fence: a fresh
    // snapshot must wait (and obey search timeout), never capture a partial boundary.
    $independent=$app->container()->get(ConnectionFactory::class)->make('pgsql');
    $independent->beginTransaction();
    try {
        $lock=$independent->prepare("SELECT pg_advisory_xact_lock_shared(hashtextextended('cajeerlogs:pagination:' || current_schema() || ':' || CAST(:project AS text),0))");
        $lock->execute(['project'=>$project]);$lock->closeCursor();
        $oldBudget=$app->config()->get('api.search_timeout_ms');$app->config()->set('api.search_timeout_ms',100);
        try { $blocked=get('/logs',$snapshot,$readerHeaders,503);verify(data($blocked)['error']==='search_timeout','snapshot waits for in-flight writer within SQL budget'); }
        finally { $app->config()->set('api.search_timeout_ms',$oldBudget); }
    } finally { $independent->rollBack(); }
    verify(count(data(get('/logs',$snapshot,$readerHeaders))['items'])===2,'snapshot works after writer fence released');
    // A large historical JSONB row must not cause unbounded page buffering or
    // disappear silently. Two 1.2 MiB rows force a byte-limited page.
    $pdo->prepare("UPDATE log_events SET context=jsonb_build_object('large',repeat('x',1200000)) WHERE id IN (CAST(:a AS uuid),CAST(:b AS uuid))")->execute(['a'=>$d,'b'=>$c]);
    $short=data(get('/logs',$query+['limit'=>'100'],$readerHeaders));
    verify(count($short['items'])===1&&$short['items'][0]['id']===$d&&$short['next_cursor']!==null,'real SQL byte limit shortens page without skipping');
    $after=data(get('/logs',$query+['limit'=>'100','cursor'=>$short['next_cursor']],$readerHeaders));
    verify($after['items'][0]['id']===$c,'byte-limited continuation starts at first omitted event');
    $pdo->prepare("UPDATE log_events SET context=jsonb_build_object('large',repeat('x',2200000)) WHERE id=:id")->execute(['id'=>$d]);
    get('/logs',$query+['limit'=>'100'],$readerHeaders,413);
    verify(strlen(data(get('/logs/'.$d,$query,$readerHeaders))['data']['context']['large'])===2200000,'detail can read event larger than page budget');
    $pdo->prepare("UPDATE log_events SET context=jsonb_build_object('large',repeat('x',4300000)) WHERE id=:id")->execute(['id'=>$d]);
    get('/logs/'.$d,$query,$readerHeaders,413);
    get('/logs/'.$d,$query,bearer($foreignReader['token']),403);

    $pdo->prepare("UPDATE log_events SET context='{}'::jsonb WHERE id IN (CAST(:a AS uuid),CAST(:b AS uuid))")->execute(['a'=>$d,'b'=>$c]);
    $pdo->prepare('DELETE FROM log_events WHERE id=:id')->execute(['id'=>$c]);
    verify(array_column(data(get('/logs',$snapshot+['cursor'=>$first['next_cursor']],$readerHeaders))['items'],'id')===$ties,'deleting the anchor does not break keyset continuation');
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
        $result=file_get_contents('http://'.$address.'/api/v1/logs?'.http_build_query($snapshot+['cursor'=>$first['next_cursor']]),false,$ctx);
        verify($result!==false&&str_contains($http_response_header[0]??'',' 200 ')&&array_column(json_decode($result,true,32,JSON_THROW_ON_ERROR)['items'],'id')===$ties,'signed cursor survives independent PHP process');
    } finally { proc_terminate($process); proc_close($process); if (is_file($log)) { unlink($log); } }
    echo "Search PostgreSQL: $count checks passed\n";
} finally {
    if (isset($pdo)&&$pdo->inTransaction()) { $pdo->rollBack(); }
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    foreach (glob($tmp.'/*.sql') as $file) { unlink($file); } rmdir($tmp);
}
