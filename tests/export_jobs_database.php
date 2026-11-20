<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository,UserRepository};
use Cajeer\Logs\Http\{Request,Response};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{ExportQueue,IngestValidator,LogRepository};

if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Require disposable PostgreSQL: CAJEER_TEST_DATABASE=1 and DB_*\n");exit(1); }
$root=dirname(__DIR__); $bootstrap=require $root.'/core/bootstrap.php'; $bootstrap->boot();
$base=$bootstrap->container()->get(PDO::class);$schema='cl_export_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;$artifacts=[];
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function api(string $method,string $path,?array $payload=null,array $headers=[],array $query=[]):Response {
    global $app;
    if($payload!==null) $headers['Content-Type']='application/json';
    return $app->handle(new Request($method,'/api/v1'.$path,$headers,$query,$payload===null?'':json_encode((object)$payload,JSON_THROW_ON_ERROR),'127.0.0.1'));
}
function body(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function login(string $email):array {
    $r=api('POST','/auth/login',['email'=>$email,'password'=>'test-password-123456']);
    check($r->status===200,'login '.$email);
    return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>body($r)['csrf_token']];
}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$app->config()->set('api.rate_limit',1000);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $u=new UserRepository($pdo);$adminId=$u->create('expadmin@example.test','test-password-123456','administrator');$u->create('expviewer@example.test','test-password-123456','viewer');
    $projects=new ProjectRepository($pdo);$project=$projects->create('Export A','export-a');$other=$projects->create('Export B','export-b');
    $source=(new SourceRepository($pdo))->create($project,'App','app');
    $writer=(new TokenRepository($pdo))->create('writer',['logs:write'],$project);
    $time=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
    $messages=[];
    for($i=0;$i<125;$i++) $messages[]=['source'=>'app','level'=>'info','timestamp'=>$time,'message'=>$i===4?'=SUM(2,2)':'log-'.$i,'context'=>(object)['index'=>$i],'tags'=>[]];
    $repo=new LogRepository($pdo);
    foreach(array_chunk($messages,100) as $batch) {
        $events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode(['events'=>$batch],JSON_THROW_ON_ERROR)));
        (new TransactionManager($pdo))->run(fn()=>$repo->storeBatch($events,$project,['app'=>$source],$writer['id']));
    }
    check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===125,'125 actual persisted rows across two batches');
    $admin=login('expadmin@example.test');$viewer=login('expviewer@example.test');
    $range=['from'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)];
    $url='/projects/'.$project.'/exports';
    check(api('POST',$url,['format'=>'json','filters'=>$range],['Cookie'=>$admin['Cookie']])->status===403,'CSRF required for export request');
    check(api('POST',$url,['format'=>'json','filters'=>$range],$viewer)->status===403,'viewer cannot request an export');
    check(api('POST',$url,['format'=>'json','filters'=>['project_id'=>$other]+$range],$admin)->status===422,'project override rejected');
    check(api('POST',$url,['format'=>'json','filters'=>['from'=>'2025-01-01T00:00:00Z']+$range],$admin)->status===422,'oversized range rejected');
    $response=api('POST',$url,['format'=>'json','filters'=>$range],$admin);check($response->status===202,'JSON export queued');
    $job=body($response)['job'];$id=$job['id'];$artifacts[]=$id.'.json';
    check(api('GET','/projects/'.$other.'/exports/'.$id,null,$admin)->status===404,'cross-project export hidden');
    check(api('GET',$url,null,['Authorization'=>'Bearer '.$writer['token']])->status===403,'token cannot access session-owned exports');
    $queue=new ExportQueue($pdo,$root.'/storage/exports/private');$result=$queue->work(2);
    check($result['ready']===1&&$result['failed']===0,'PostgreSQL export worker completes real job');
    $ready=body(api('GET',$url.'/'.$id,null,$admin))['job'];
    check($ready['status']==='ready'&&$ready['row_count']===125&&$ready['byte_count']>0&&strlen($ready['checksum'])===64,'ready metadata has row count, bytes and SHA-256');
    $resp=api('GET',$url.'/'.$id.'/download',null,$admin);check($resp->status===200&&$resp->header('Content-Length')===(string)$ready['byte_count'],'authenticated binary download');
    ob_start();$resp->send();$download=ob_get_clean();
    $items=json_decode($download,true,64,JSON_THROW_ON_ERROR);
    check(count($items)===125&&hash('sha256',$download)===$ready['checksum'],'JSON multi-page export is complete and intact');
    check(api('GET',$url.'/'.$id.'/download',null,$viewer)->status===403,'viewer cannot download owner file');
    $csv=body(api('POST',$url,['format'=>'csv','filters'=>$range],$admin))['job'];$artifacts[]=$csv['id'].'.csv';
    check($queue->work(1)['ready']===1,'CSV worker completes job');
    $csvResp=api('GET',$url.'/'.$csv['id'].'/download',null,$admin);ob_start();$csvResp->send();$csvBody=ob_get_clean();
    check(str_contains($csvBody,"'=SUM(2,2)")&&substr_count($csvBody,"\r\n")===126,'CSV rows complete with spreadsheet formula neutralized');
    $path=$root.'/storage/exports/private/'.$id.'.json';file_put_contents($path,'corruption',FILE_APPEND);
    check(api('GET',$url.'/'.$id.'/download',null,$admin)->status===410,'tampered private file rejected by SHA-256');
    $pdo->prepare("UPDATE log_export_jobs SET expires_at=now()-interval '1 minute' WHERE id=CAST(:id AS uuid)")->execute(['id'=>$csv['id']]);
    check($queue->cleanup()['expired']>=1,'expired export cleanup executed');
    check(api('GET',$url.'/'.$csv['id'].'/download',null,$admin)->status===409,'expired export never downloadable');
    // Exhausted crash-retries are transitioned into a terminal state rather than
    // being stuck in 'processing' forever after the worker disappears.
    $stuck=body(api('POST',$url,['format'=>'json','filters'=>$range],$admin))['job'];
    $pdo->prepare("UPDATE log_export_jobs SET status='processing',attempts=3 WHERE id=CAST(:id AS uuid)")->execute(['id'=>$stuck['id']]);
    $queue->work(1);
    $failed=body(api('GET',$url.'/'.$stuck['id'],null,$admin))['job'];
    check($failed['status']==='failed'&&$failed['error_code']==='attempts_exhausted','interrupted third attempt converges to failed state');
    $actions=$pdo->query("SELECT action FROM audit_log WHERE action LIKE 'logs.export_%'")->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('logs.export_requested',$actions,true)&&in_array('logs.export_completed',$actions,true)&&in_array('logs.export_downloaded',$actions,true),'export lifecycle is auditable');
    $auditRecord=$pdo->prepare("SELECT actor_type,result,target,project_id FROM audit_log WHERE action='logs.export_completed' AND target=:target");
    $auditRecord->execute(['target'=>$id]);
    $record=$auditRecord->fetch(PDO::FETCH_ASSOC);
    check($record && $record['actor_type']==='user'&&$record['result']==='success'
        &&$record['target']===$id&&$record['project_id']===$project,
        'completed export audit preserves actor, outcome and project scope');
    echo "Bulk export PostgreSQL integration: $n tests passed\n";
} finally {
    foreach($artifacts as $file){$path=$root.'/storage/exports/private/'.$file;if(is_file($path))@unlink($path);}
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
}
