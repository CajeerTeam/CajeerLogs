<?php
declare(strict_types=1);

use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\SignedPayload;
use Cajeer\Logs\Console\Command\AuthCleanupCommand;

if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_DATABASE=1 and DB_* for an isolated PostgreSQL test database.\n"); exit(1); }
$root=dirname(__DIR__); $baseApp=require $root.'/core/bootstrap.php'; $baseApp->boot();
$base=$baseApp->container()->get(PDO::class); $schema='cl_ingest_'.bin2hex(random_bytes(8));
$base->exec('CREATE SCHEMA "'.$schema.'"'); $tmp=sys_get_temp_dir().'/'.$schema; mkdir($tmp,0700);
$count=0;
function verify(bool $ok,string $label):void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$label); } ++$count; echo "PASS $label\n"; }
function encode(mixed $d):string { return json_encode($d,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
function data(Response $r):array { return json_decode($r->body,true,32,JSON_THROW_ON_ERROR); }
function send(string $raw,string $token,array $headers=[],string $ip='127.0.0.1'):Response {
    global $app;
    return $app->handle(new Request('POST','/api/v1/ingest',$headers+['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],[],$raw,$ip));
}
function expect(string $raw,string $token,int $status,string $label,array $headers=[],string $ip='127.0.0.1'):Response {
    $r=send($raw,$token,$headers,$ip); verify($r->status===$status,$label.' (HTTP '.$r->status.')'); return $r;
}
function signed(string $raw,string $token,?string $key=null,?string $nonce=null,?int $time=null):array {
    $timestamp=(string)($time??time()); $nonce=$nonce??bin2hex(random_bytes(16));
    $signature=(new SignedPayload())->sign(SignedPayload::canonical(new Request('POST','/api/v1/ingest',[],[],$raw),$timestamp,$nonce,$key),$token);
    return ['X-Log-Timestamp'=>$timestamp,'X-Log-Nonce'=>$nonce,'X-Log-Signature'=>$signature]+($key===null?[]:['Idempotency-Key'=>$key]);
}
try {
    $app=new Application($root); $app->boot(); $app->config()->set('database.schema',$schema); $app->config()->set('app.env','test'); $app->config()->set('app.url','http://localhost:8080'); $app->config()->set('api.rate_limit',600); $app->config()->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);
    foreach (['0001_initial_schema.sql','0002_projects_sources.sql','0003_identity_access.sql'] as $file) { copy($root.'/migrations/pgsql/'.$file,$tmp.'/'.$file); }
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $projects=new ProjectRepository($pdo); $sources=new SourceRepository($pdo); $tokens=new TokenRepository($pdo);
    $project=$projects->create('First','first'); $foreign=$projects->create('Second','second');
    $source=$sources->create($project,'Application','app'); $other=$sources->create($project,'Other','other'); $sources->create($foreign,'Foreign','foreign');
    $writer=$tokens->create('project writer',['logs:write'],$project); $scoped=$tokens->create('source writer',['logs:write'],$project,$source); $reader=$tokens->create('reader',['logs:read'],$project,$source);
    $legacy=$pdo->query("INSERT INTO log_events(occurred_at,source,level,message) VALUES(now(),'legacy','info','historic event') RETURNING id")->fetchColumn();
    $runner=$app->container()->get(MigrationRunner::class);
    verify($runner->migrate()===['0004_log_ingestion.sql','0005_log_search.sql','0006_sdk_integrations.sql','0007_queue_lifecycle.sql','0008_security_operations.sql','0009_project_management.sql','0010_source_management.sql']&&$runner->migrate()===[],'0.0.3 -> current upgrade and repeat');
    verify($pdo->query("SELECT id FROM log_events WHERE source='legacy'")->fetchColumn()===$legacy,'historic unscoped row preserved');
    verify($app->handle(new Request('GET','/ready'))->status===200,'readiness after migration');
    $event=['timestamp'=>'2026-01-02T03:04:05.123456+03:00','source'=>'app','level'=>'ERROR','message'=>'login password=hidden-value','service'=>'backend','host'=>'host1','trace_id'=>'trace-1','request_id'=>'req-1','context'=>(object)['password'=>'hidden-context','nested'=>(object)['apiKey'=>'another-secret'],'empty'=>(object)[],'list'=>[]],'tags'=>['one','two','one']];
    $raw=encode($event); $secret=$writer['token'];
    $before=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    $r=expect($raw,$secret,201,'single committed event'); $d=data($r); $id=$d['event_ids'][0];
    verify($d['accepted']===true&&$d['stored']===1&&$d['received']===1&&$d['rejected']===0&&!$d['replayed'],'truthful commit response');
    // A separate connection proves the row is committed, not just visible inside the transaction.
    $separate=$app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->make('pgsql');
    $stmt=$separate->prepare("SELECT *,to_char(occurred_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS.US') AS utc,to_json(tags)::text AS tags_json FROM log_events WHERE id=:id");
    $stmt->execute(['id'=>$id]); $row=$stmt->fetch();
    verify($row['project_id']===$project&&$row['source_id']===$source&&$row['ingest_token_id']===$writer['id'],'stored project/source/token attribution');
    verify($row['utc']==='2026-01-02 00:04:05.123456'&&$row['level']==='error'&&$row['service']==='backend'&&$row['host']==='host1'&&$row['trace_id']==='trace-1'&&$row['request_id']==='req-1'&&json_decode($row['tags_json'],true)===['one','two'],'stored normalized metadata');
    $ctx=json_decode($row['context']); verify($ctx->password==='[redacted]'&&$ctx->nested->apiKey==='[redacted]'&&$ctx->empty instanceof stdClass&&$ctx->list===[]&&!str_contains($row['message'],'hidden-value'),'secrets masked before persistence');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$before+1,'single event persisted');
    $batch=encode(['events'=>[$event,array_replace($event,['source'=>'other','message'=>'second'])]]);
    $r=expect($batch,$secret,201,'project token batch with two sources',['Idempotency-Key'=>'batch-1']); $ids=data($r)['event_ids']; verify(count($ids)===2&&$ids[0]!==$ids[1],'batch generated distinct UUIDs');
    $r=expect($batch,$secret,200,'idempotent retry',['Idempotency-Key'=>'batch-1']); verify(data($r)['event_ids']===$ids&&data($r)['stored']===0&&data($r)['replayed']===true,'same UUIDs without duplicate inserts');
    expect($raw,$secret,409,'key reused with different body',['Idempotency-Key'=>'batch-1']);
    $countBefore=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    expect($batch,$scoped['token'],403,'source-bound token cannot write another source');
    expect(encode(array_replace($event,['source'=>'foreign'])),$secret,403,'foreign source denied');
    expect($raw,$reader['token'],403,'read-only token cannot write');
    expect($raw,'cl_'.str_repeat('0',64),401,'unknown token');
    expect(encode(['events'=>[$event,array_replace($event,['timestamp'=>'bad'])]]),$secret,422,'invalid batch rejected');
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$countBefore,'denials, replay and invalid batch do not add rows');
    expect($raw,$scoped['token'],201,'source-bound token can write assigned source',['Idempotency-Key'=>'batch-1']);
    // Untracked DDL must block HTTP; independently exercise actual bulk INSERT rollback.
    $pdo->exec("ALTER TABLE log_events ADD CONSTRAINT ingest_test_reject CHECK (message <> 'force-db-failure')");
    $bad=encode(['events'=>[$event,array_replace($event,['message'=>'force-db-failure'])]]);
    $countBefore=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    expect($bad,$secret,503,'untracked constraint blocks ingest before SQL',['Idempotency-Key'=>'rollback-1']);
    $pdo->beginTransaction();
    try {
        $events=(new \Cajeer\Logs\Log\IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$bad));
        (new \Cajeer\Logs\Log\LogRepository($pdo))->storeBatch($events,$project,['app'=>$source],$writer['id']);
        throw new RuntimeException('Expected actual constraint failure');
    } catch (PDOException $e) { verify((string)$e->getCode()==='23514','real batch constraint failure'); }
    finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    verify((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$countBefore,'failed transaction rolled back first row');
    $pdo->exec('ALTER TABLE log_events DROP CONSTRAINT ingest_test_reject');
    expect($bad,$secret,201,'failed attempt did not reserve idempotency key',['Idempotency-Key'=>'rollback-1']);
    $app->config()->set('ingest.require_hmac',true);
    expect($raw,$secret,401,'mandatory signature');
    $h=signed($raw,$secret,'signed-1');
    $signedResult=data(expect($raw,$secret,201,'signed ingest',$h));
    expect($raw,$secret,409,'nonce replay rejected',$h);
    expect($raw.' ',$secret,401,'signed body tampering',$h);
    expect($raw,$secret,401,'expired signature',signed($raw,$secret,null,null,time()-301));
    $r=expect($raw,$secret,200,'signed idempotent retry with fresh nonce',signed($raw,$secret,'signed-1')); verify(data($r)['event_ids']===$signedResult['event_ids'],'signed retry returns original UUID');
    $app->config()->set('ingest.require_hmac',false);
    // Receipts and nonces expire, and cleanup does not delete log events.
    $pdo->exec("UPDATE ingest_receipts SET expires_at=now()-interval '1 second'; UPDATE ingest_nonces SET expires_at=now()-interval '1 second'");
    $countBefore=(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    verify((new AuthCleanupCommand($app))->handle()===0&&(int)$pdo->query('SELECT count(*) FROM ingest_receipts')->fetchColumn()===0&&(int)$pdo->query('SELECT count(*) FROM ingest_nonces')->fetchColumn()===0&&(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===$countBefore,'cleanup removes expired security metadata only');
    $expiring=$tokens->create('expiring',['logs:write'],$project,null,new DateTimeImmutable('+1 hour'));
    $stmt=$pdo->prepare("UPDATE api_tokens SET expires_at=now()-interval '1 second' WHERE id=:id");$stmt->execute(['id'=>$expiring['id']]);
    expect($raw,$expiring['token'],401,'expired token'); $tokens->revoke($scoped['id']); expect($raw,$scoped['token'],401,'revoked token');
    $limited=$tokens->create('limited',['logs:write'],$project); $app->config()->set('api.rate_limit',1);
    expect($raw,$limited['token'],201,'first rate-limited request',[],'192.0.2.100');
    $r=expect($raw,$limited['token'],429,'token rate limit across IPs',[],'192.0.2.101'); verify($r->header('Retry-After')==='60','retry-after header');
    $app->config()->set('api.rate_limit',600);
    // Two independent PHP processes race the same token/key, held initially on its row lock.
    $program = <<<'WORKER'
$app=require 'core/bootstrap.php';
$request=new \Cajeer\Logs\Http\Request('POST','/api/v1/ingest',['Authorization'=>'Bearer '.getenv('CL_TEST_TOKEN'),'Content-Type'=>'application/json','Idempotency-Key'=>'parallel-key'],[],getenv('CL_TEST_BODY'));
$response=$app->handle($request); echo json_encode(['status'=>$response->status,'body'=>json_decode($response->body,true)],JSON_THROW_ON_ERROR);
WORKER;
    $workers=[];
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM api_tokens WHERE id=:id FOR UPDATE'); $lock->execute(['id'=>$writer['id']]); $lock->closeCursor();
        for ($i=0;$i<2;$i++) {
            $env=array_replace(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','DB_SCHEMA'=>$schema,'INGEST_REQUIRE_HMAC'=>'false','API_RATE_LIMIT_PER_MINUTE'=>'600','CL_TEST_TOKEN'=>$secret,'CL_TEST_BODY'=>$raw]);
            $child=proc_open([PHP_BINARY,'-r',$program],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$streams,$root,$env);
            if (!is_resource($child)) { throw new RuntimeException('Cannot start concurrent worker.'); }
            fclose($streams[0]); $workers[]=[$child,$streams];
        }
        usleep(250000); $pdo->commit();
        $results=[];
        foreach ($workers as [$child,$streams]) {
            $out=stream_get_contents($streams[1]); $err=stream_get_contents($streams[2]); fclose($streams[1]); fclose($streams[2]);
            if (proc_close($child)!==0) { throw new RuntimeException('Concurrent worker failed: '.$err); }
            $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
        }
        $workers=[]; $statuses=array_column($results,'status'); sort($statuses);
        verify($statuses===[200,201]&&$results[0]['body']['event_ids']===$results[1]['body']['event_ids'],'concurrent idempotent writes yield one commit and one replay');
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($workers as [$child,$streams]) {
            if (is_resource($child)) { proc_terminate($child); }
            foreach ($streams as $stream) { if (is_resource($stream)) { fclose($stream); } }
            if (is_resource($child)) { proc_close($child); }
        }
    }
    // Network HTTP across PHP request lifecycles and independent DB connection.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); if ($socket===false) { throw new RuntimeException('Cannot allocate test port.'); }
    $address=stream_socket_get_name($socket,false); fclose($socket); $log=$tmp.'/http.log';
    $env=array_replace(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','APP_URL'=>'http://'.$address,'DB_SCHEMA'=>$schema,'INGEST_REQUIRE_HMAC'=>'false','API_RATE_LIMIT_PER_MINUTE'=>'600']);
    $process=proc_open([PHP_BINARY,'-S',$address,'-t','public','public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); } fclose($pipes[0]);
    try {
        $ready=false; for ($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if ($probe!==false) { fclose($probe); $ready=true; break; } usleep(50000); } verify($ready,'network HTTP server running');
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer $secret\r\nIdempotency-Key: network-1\r\n",'content'=>$raw,'ignore_errors'=>true,'timeout'=>15]]);
        $result=file_get_contents('http://'.$address.'/api/v1/ingest',false,$ctx); verify($result!==false&&str_contains($http_response_header[0]??'',' 201 '),'real network POST committed');
        $network=json_decode($result,true,32,JSON_THROW_ON_ERROR); $stmt=$separate->prepare('SELECT count(*) FROM log_events WHERE id=:id'); $stmt->execute(['id'=>$network['event_ids'][0]]); verify((int)$stmt->fetchColumn()===1,'network event visible in PostgreSQL');
        $result=file_get_contents('http://'.$address.'/api/v1/ingest',false,$ctx); verify($result!==false&&str_contains($http_response_header[0]??'',' 200 ')&&json_decode($result,true)['event_ids']===$network['event_ids'],'network idempotent replay across requests');
        // Real SDK/collector requests use the same network endpoint and an independent connection.
        $sdkEnv=array_replace($env,['CL_TEST_URL'=>'http://'.$address,'CL_TEST_TOKEN'=>$secret]);
        $sdkCommands=[['python3','tests/sdk_database_client.py']];
        if (is_file($root.'/vendor/autoload.php')) { $sdkCommands[]=[PHP_BINARY,'tests/sdk/php_database.php']; }
        foreach ($sdkCommands as $command) {
            $sdk=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$sdkPipes,$root,$sdkEnv);
            if (!is_resource($sdk)) { throw new RuntimeException('Cannot start SDK test.'); }
            fclose($sdkPipes[0]);$sdkOut=stream_get_contents($sdkPipes[1]);$sdkErr=stream_get_contents($sdkPipes[2]);fclose($sdkPipes[1]);fclose($sdkPipes[2]);
            verify(proc_close($sdk)===0,'real SDK HTTP integration: '.$command[1]);echo $sdkOut;
        }
        $stmt=$separate->prepare('SELECT count(*) FROM log_events WHERE message=:message');
        foreach (['sdk real postgres event','sdk collector real postgres event'] as $message) { $stmt->execute(['message'=>$message]);verify((int)$stmt->fetchColumn()===1,'SDK event committed exactly once'); }
        if (count($sdkCommands)>1) { foreach (['sdk psr3 real postgres event','sdk monolog real postgres event'] as $message) { $stmt->execute(['message'=>$message]);verify((int)$stmt->fetchColumn()===1,'logging adapter event committed'); } }
    } finally { proc_terminate($process); proc_close($process); if (is_file($log)) { unlink($log); } }
    echo "Ingest PostgreSQL: $count checks passed\n";
} finally {
    if (isset($pdo)&&$pdo->inTransaction()) { $pdo->rollBack(); }
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    foreach (glob($tmp.'/*.sql') as $file) { unlink($file); } rmdir($tmp);
}
