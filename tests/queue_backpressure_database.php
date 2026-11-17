<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueAccounting,BackpressurePolicy};
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Log\IngestEvent;
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Disposable PostgreSQL required.\n");exit(1);}
$root=dirname(__DIR__);require $root.'/core/bootstrap.php';$seed=new Application($root);$seed->boot();$base=$seed->container()->get(PDO::class);
$schema='cl_bp_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function check(bool $v,string $name):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";$n++;}
try {
    $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('ingest.require_hmac',false);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $p=(new ProjectRepository($pdo))->create('Backpressure','backpressure');$source=(new SourceRepository($pdo))->create($p,'Service','service');$token=(new TokenRepository($pdo))->create('Writer',['logs:write'],$p,$source);
    $events=[new IngestEvent('service','info','event A',(object)[],date('Y-m-d\TH:i:s\Z')),
             new IngestEvent('service','info','event B',(object)[],date('Y-m-d\TH:i:s\Z'))];
    $map=['service'=>$source];$account=new QueueAccounting($pdo);
    $writer=function(string $key,int $count,BackpressurePolicy $policy)use($pdo,$token,$p,$events,$map):array|HttpException {
        return (new TransactionManager($pdo))->run(fn()=>(new PostgresQueueDriver($pdo,$policy->maxJobs,null,new \Cajeer\Logs\Queue\RetryPolicy(),$policy))->enqueue($token['id'],$p,hash('sha256',$key),hash('sha256','body-'.$key),array_slice($events,0,$count),$map));
    };
    $policy=new BackpressurePolicy(10,3,0,100,50);
    $first=$writer('events-first',2,$policy);check(is_array($first)&&$account->snapshot()['active_events']===2,'first durable two-event job');
    $tooMany=$writer('events-over',2,$policy);check($tooMany instanceof HttpException&&$tooMany->status===503&&$tooMany->error==='queue_full'&&$account->status()['rejected_events']===1&&$account->verify()['valid'],'event limit denies without altering queue');
    check($writer('events-first',2,$policy)['job_id']===$first['job_id'],'idempotent replay succeeds at cap');
    $single=$writer('events-next',1,$policy);check(is_array($single)&&$account->status()['admission_paused']===1,'exact event cap accepted and high watermark latched');
    $paused=$writer('events-paused',1,$policy);check($paused instanceof HttpException&&$account->status()['rejected_paused']===1,'paused rejection durable telemetry');
    $worker=new PostgresQueueDriver($pdo,10);check($worker->process($worker->claim())==='completed'&&$account->snapshot()['active_events']===1,'completion frees event count');
    $resumed=$writer('events-resumed',1,$policy);check(is_array($resumed)&&$account->status()['admission_paused']===0,'below low watermark resumes');
    while(($claim=$worker->claim())!==null){if(isset($claim['id']))check($worker->process($claim)==='completed','drain accepted job');else break;}
    check($account->snapshot()['active_jobs']===0&&$account->verify()['valid'],'zero capacity after drains');
    $batch=\Cajeer\Logs\Log\IngestBatch::encodeJob([$events[0]],$map);
    $stmt=$pdo->prepare('SELECT octet_length(CAST(:body AS jsonb)::text)');$stmt->execute(['body'=>$batch]);$exactBytes=(int)$stmt->fetchColumn();
    $bytePolicy=new BackpressurePolicy(10,0,$exactBytes+2,100,50);
    $a=$writer('bytes-first',1,$bytePolicy);check(is_array($a)&&$account->status()['active_bytes']>0,'byte cap measured on stored JSONB');
    $b=$writer('bytes-second',1,$bytePolicy);check($b instanceof HttpException&&$account->status()['rejected_bytes']===1,'byte hard cap blocks second job');
    check($worker->process($worker->claim())==='completed'&&$account->verify()['valid'],'byte release after processing');
    // Two genuinely separate PHP processes compete on the same PostgreSQL row.
    $hard=new BackpressurePolicy(10,1,0,100,0);$barrier=sys_get_temp_dir().'/caj-bp-start-'.$schema;
    $code='require '.var_export($root.'/core/bootstrap.php',true).';$a=new \\Cajeer\\Logs\\Kernel\\Application('.var_export($root,true).');$a->boot();$a->config()->set("database.schema",'.var_export($schema,true).');$pdo=$a->container()->get(PDO::class);$policy=new \\Cajeer\\Logs\\Queue\\BackpressurePolicy(10,1,0,100,0);$q=new \\Cajeer\\Logs\\Queue\\PostgresQueueDriver($pdo,10,null,new \\Cajeer\\Logs\\Queue\\RetryPolicy(),$policy);$e=new \\Cajeer\\Logs\\Log\\IngestEvent("service","info","parallel",(object)[],gmdate("Y-m-d\\TH:i:s\\Z"));$start=microtime(true);while(!file_exists('.var_export($barrier,true).')){if(microtime(true)-$start>10)exit(3);usleep(10000);}try{$pdo->beginTransaction();$r=$q->enqueue('.var_export($token['id'],true).','.var_export($p,true).',hash("sha256",(string)getmypid()),hash("sha256","parallel"),[$e],["service"=>'.var_export($source,true).']);$pdo->commit();echo $r instanceof \\Cajeer\\Logs\\Http\\HttpException?"blocked":"accepted";}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}';
    $procs=[];
    for($i=0;$i<2;$i++){$proc=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);if(!is_resource($proc))throw new RuntimeException('Cannot start producer');fclose($pipes[0]);$procs[]=[$proc,$pipes];}
    touch($barrier);$results=[];
    foreach($procs as [$proc,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0)throw new RuntimeException('Producer failed: '.$error);$results[]=$output;}
    @unlink($barrier);
    sort($results);check($results===['accepted','blocked']&&$account->snapshot()['active_events']===1&&$account->verify()['valid'],'two independent producers never overshoot event capacity');
    $latest=$account->status();check($latest['rejected_events']===2&&$latest['admission_paused']===1,'rejection counters persist across transactions');
    $worker->process($worker->claim());check($account->snapshot()['active_jobs']===0&&$account->verify()['valid'],'final reconciliation exact');
    echo "Queue backpressure database: $n checks passed\n";
} finally {$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
