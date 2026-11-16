<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,ConnectionManager,DatabaseFailure,MigrationRunner};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Queue\PostgresQueueDriver;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use a disposable PostgreSQL database: CAJEER_TEST_DATABASE=1.\n");exit(1);}
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();
$base=$app->container()->get(PDO::class);$schema='cl_runtime_'.bin2hex(random_bytes(6));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;$worker=null;$log=tempnam(sys_get_temp_dir(),'cl-worker-');
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function waitFor(callable $f,string $label,int $timeout=30):mixed{ $until=microtime(true)+$timeout;do{try{$r=$f();if($r)return $r;}catch(PDOException|\Cajeer\Logs\Database\DatabaseException $e){}usleep(200000);}while(microtime(true)<$until);throw new RuntimeException('Timeout: '.$label); }
function terminate(PDO $pdo,int $pid):void{$s=$pdo->prepare('SELECT pg_terminate_backend(CAST(:pid AS integer),5000)');$s->execute(['pid'=>$pid]);if(!$s->fetchColumn())throw new RuntimeException('Unable to terminate test backend');$s->closeCursor();}
try {
    $cfg=clone $app->config();$cfg->set('database.schema',$schema);$cfg->set('database.application_name','cl_runtime_probe');$cfg->set('database.statement_timeout_ms','4321');$cfg->set('database.lock_timeout_ms','1234');$cfg->set('database.idle_transaction_timeout_ms','6789');
    $factory=new ConnectionFactory($cfg);$manager=new ConnectionManager($factory);$pdo=$manager->connection();
    check($pdo->query('SELECT current_schema()')->fetchColumn()===$schema && $pdo->query("SELECT current_setting('application_name')")->fetchColumn()==='cl_runtime_probe','real session schema and application_name');
    check($pdo->query('SHOW statement_timeout')->fetchColumn()==='4321ms'&&$pdo->query('SHOW lock_timeout')->fetchColumn()==='1234ms'&&$pdo->query('SHOW idle_in_transaction_session_timeout')->fetchColumn()==='6789ms','real server timeouts');
    check($pdo->query('SHOW TimeZone')->fetchColumn()==='UTC'&&$pdo->query('SHOW client_encoding')->fetchColumn()==='UTF8'&&!$pdo->getAttribute(PDO::ATTR_PERSISTENT),'nonpersistent UTC UTF8 session');
    $pid=(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn();$pdo->beginTransaction();
    try{$manager->connection();throw new RuntimeException('Expected transaction guard');}catch(LogicException){check(true,'active transaction preserved');} $pdo->rollBack();
    terminate($base,$pid);try{$manager->probe();throw new RuntimeException('Expected lost session');}catch(PDOException $e){check(DatabaseFailure::reconnectable($e),'actual terminated backend classified');}
    $pdo=$manager->connection();check((int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()!==$pid&&$manager->metrics()['connections']===2,'new live session after backend termination');
    check($pdo->query('SELECT current_schema()')->fetchColumn()===$schema,'session initialized again after reconnect');
    // Actual PostgreSQL authentication errors fail without retry on the CI SCRAM service.
    if(getenv('CAJEER_TEST_POSTGRES_CONTAINER')){
        $badCfg=clone $cfg;$badCfg->set('database.password','definitely-wrong-test-password');$bad=new ConnectionFactory($badCfg);
        try{$bad->make('pgsql');throw new RuntimeException('Expected authentication failure');}catch(\Cajeer\Logs\Database\DatabaseException $e){check($e->category==='authentication'&&$bad->attempts()['attempts']===1,'real invalid password is not retried');}
        $closed=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);if($closed===false)throw new RuntimeException('Cannot reserve test port');$address=stream_socket_get_name($closed,false);fclose($closed);
        $badCfg=clone $cfg;$badCfg->set('database.port',substr(strrchr($address,':'),1));$badCfg->set('database.connect_attempts','3');$badCfg->set('database.connect_retry_ms','10');$bad=new ConnectionFactory($badCfg);
        try{$bad->make('pgsql');throw new RuntimeException('Expected connection refusal');}catch(\Cajeer\Logs\Database\DatabaseException $e){check(DatabaseFailure::reconnectable($e)&&$bad->attempts()['attempts']===3,'real connection refusal retries exactly three times');}
    }
    // Real query timeout does not trigger a reconnect or a statement replay.
    $pdo->exec('SET statement_timeout=100');try{$pdo->query('SELECT pg_sleep(1)');throw new RuntimeException('Expected statement timeout');}catch(PDOException $e){check(DatabaseFailure::category($e)==='statement_timeout'&&!DatabaseFailure::reconnectable($e),'real statement timeout not a reconnect');}$pdo->exec('SET statement_timeout=4321');
    (new MigrationRunner($pdo,$root.'/migrations/pgsql',$schema))->migrate();
    $project=(new ProjectRepository($pdo))->create('Reconnect','reconnect');$source=(new SourceRepository($pdo))->create($project,'App','app');$token=(new TokenRepository($pdo))->create('writer',['logs:write'],$project,$source);
    $api=new Application($root);$api->boot();$api->config()->set('database.schema',$schema);$api->config()->set('app.env','test');$api->config()->set('ingest.require_hmac',false);
    $api->config()->set('operations.metrics_token','');
    $metrics=$api->handle(new Request('GET','/metrics'));check($metrics->status===200&&str_contains($metrics->body,'cajeerlogs_postgres_available 1')&&str_contains($metrics->body,'cajeerlogs_postgres_probe_seconds ')&&str_contains($metrics->body,'cajeerlogs_postgres_sessions_total '),'actual PostgreSQL metrics and query probe');
    $send=function(string $key)use(&$api,$token):string{$r=$api->handle(new Request('POST','/api/v1/ingest/async',['Authorization'=>'Bearer '.$token['token'],'Content-Type'=>'application/json','Idempotency-Key'=>$key],[],json_encode(['timestamp'=>'2026-11-14T00:00:00Z','source'=>'app','level'=>'info','message'=>$key],JSON_THROW_ON_ERROR),'127.0.0.1'));if($r->status!==202)throw new RuntimeException('Expected durable async receipt: '.$r->status);return json_decode($r->body,true,32,JSON_THROW_ON_ERROR)['job_id'];};
    $id=$send('lost-lease');$queue=new PostgresQueueDriver($pdo);$claim=$queue->claim();check((string)$claim['id']===$id,'job claimed before simulated connection failure');
    terminate($base,(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn());
    try{$queue->process($claim);throw new RuntimeException('Expected lost session during processing');}catch(PDOException $e){check(DatabaseFailure::reconnectable($e),'lost worker session does not mark job completed');}
    $pdo=$factory->make('pgsql');check($pdo->query('SELECT status FROM jobs WHERE id='.$id)->fetchColumn()==='running','durable lease retained after disconnection');$pdo->exec("UPDATE jobs SET lease_until=now()-interval '1 hour' WHERE id=".$id);
    $name='cl_worker_'.bin2hex(random_bytes(6));$env=array_merge(getenv(),['APP_ENV'=>'test','APP_URL'=>'http://localhost:8080','DB_SCHEMA'=>$schema,'DB_APPLICATION_NAME'=>$name,'DB_CONNECT_ATTEMPTS'=>'2','DB_CONNECT_TIMEOUT'=>'2']);
    $worker=proc_open([PHP_BINARY,$root.'/bin/cajeer','queue:work'],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);if(!is_resource($worker))throw new RuntimeException('Cannot start real queue worker');
    $findPid=function()use(&$base,$name,$worker):int{if(!proc_get_status($worker)['running'])throw new RuntimeException('Queue worker exited');$q=$base->prepare('SELECT pid FROM pg_stat_activity WHERE application_name=:name ORDER BY backend_start DESC LIMIT 1');$q->execute(['name'=>$name]);return (int)$q->fetchColumn();};
    $workerPid=waitFor($findPid,'worker session');
    waitFor(fn()=>$pdo->query('SELECT status FROM jobs WHERE id='.$id)->fetchColumn()==='completed','expired lease completion');check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===1,'lease recovery writes exactly one event');
    terminate($base,$workerPid);$newPid=waitFor(fn()=>($p=$findPid())&&$p!==$workerPid?$p:false,'worker reconnect');check($newPid!==$workerPid,'continuous worker reconnects without process restart');
    $id2=$send('after-reconnect');waitFor(fn()=>$pdo->query('SELECT status FROM jobs WHERE id='.$id2)->fetchColumn()==='completed','second job');check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===2,'worker processes new job after reconnect');
    // CI can restart the actual service container; existing committed events must survive.
    $container=getenv('CAJEER_TEST_POSTGRES_CONTAINER');
    if($container!==false&&$container!==''){
        if(!preg_match('/^[a-f0-9]{12,64}$/D',$container))throw new RuntimeException('Invalid disposable container ID');
        $p=proc_open(['docker','restart','--time','5',$container],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('Test PostgreSQL restart failed');
        $base=waitFor(fn()=>(new ConnectionFactory($app->config()))->make('pgsql'),'restarted PostgreSQL',45);$pdo=$factory->make('pgsql');
        $api=new Application($root);$api->boot();$api->config()->set('database.schema',$schema);$api->config()->set('app.env','test');$api->config()->set('ingest.require_hmac',false);
        waitFor($findPid,'worker after server restart',45);$id3=$send('after-server-restart');waitFor(fn()=>$pdo->query('SELECT status FROM jobs WHERE id='.$id3)->fetchColumn()==='completed','job after server restart',45);
        check((int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn()===3,'PostgreSQL restart preserves events and worker resumes');
    }
    $tls=getenv('CAJEER_TEST_PG_TLS_CERT');
    if($tls!==false&&$tls!==''){
        $tlsCfg=clone $cfg;$tlsCfg->set('database.sslmode','verify-full');$tlsCfg->set('database.sslrootcert',$tls);$secure=(new ConnectionFactory($tlsCfg))->make('pgsql');
        check($secure->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn()===true,'verified TLS connection');
        $tlsCfg->set('database.sslrootcert',__FILE__);$bad=new ConnectionFactory($tlsCfg);try{$bad->make('pgsql');throw new RuntimeException('Expected certificate rejection');}catch(\Cajeer\Logs\Database\DatabaseException $e){check($e->category==='tls'&&$bad->attempts()['attempts']===1,'invalid CA rejected without retry');}
    }
    $pool=getenv('CAJEER_TEST_PGBOUNCER_PORT');
    if($pool!==false&&$pool!==''){
        $poolCfg=clone $cfg;$poolCfg->set('database.port',$pool);$poolCfg->set('database.sslmode','disable');$poolPdo=(new ConnectionFactory($poolCfg))->make('pgsql');$pid=$poolPdo->query('SELECT pg_backend_pid()')->fetchColumn();
        $poolPdo->beginTransaction();$s=$poolPdo->prepare('SELECT CAST(:value AS integer)');$s->execute(['value'=>42]);check((int)$s->fetchColumn()===42,'PgBouncer session native prepared statement');$poolPdo->commit();
        check($poolPdo->query('SELECT pg_backend_pid()')->fetchColumn()===$pid&&$poolPdo->query('SELECT current_schema()')->fetchColumn()===$schema,'PgBouncer preserves session and search_path');
    }
    echo "PostgreSQL runtime integration: $n checks passed\n";
}finally{
    if(is_resource($worker)){proc_terminate($worker,SIGTERM);$deadline=microtime(true)+5;while(proc_get_status($worker)['running']&&microtime(true)<$deadline)usleep(100000);if(proc_get_status($worker)['running'])proc_terminate($worker,SIGKILL);proc_close($worker);}
    if(is_file($log)){if(isset($n)&&$n<10)fwrite(STDERR,file_get_contents($log));unlink($log);}
    // The independent admin session may itself have died during restart.
    $cleanup=(new ConnectionFactory($app->config()))->make('pgsql');$cleanup->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
