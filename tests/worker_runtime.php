<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\{WorkerPolicy,WorkerPool,WorkerRegistry};
use Cajeer\Logs\Database\DatabaseFailure;
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';
// Child fixture exercises the real supervisor and registry, not PostgreSQL job processing.
if (($argv[1]??'')==='child') {
    $registry=new WorkerRegistry($argv[2]);$registry->start(120);$stop=false;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$stop,$argv){if (($argv[3]??'')!=='ignore') $stop=true;});
    pcntl_signal(SIGINT,function()use(&$stop){$stop=true;});
    try { $i=0;while (!$stop) { $registry->progress(++$i);usleep(50000); } }
    finally {$registry->close();} exit(0);
}
if (($argv[1]??'')==='pool') {
    $policy=new WorkerPolicy(($argv[3]??'')==='ignore'?['process_timeout_seconds'=>1,'shutdown_grace_seconds'=>1]:[]);
    exit((new WorkerPool($policy,[PHP_BINARY,__FILE__,'child',$argv[2],$argv[3]??'normal']))->run(2));
}
$n=0;
function check(bool $v,string $label): void {global $n;if (!$v) throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function rejects(callable $fn): bool {try{$fn();}catch(InvalidArgumentException){return true;}return false;}
function waitFor(callable $fn,float $seconds=15): mixed {$end=microtime(true)+$seconds;do {if ($r=$fn()) return $r;usleep(50000);}while(microtime(true)<$end);throw new RuntimeException('Timed out waiting for process state.');}
$policy=new WorkerPolicy();
check($policy->get('processes')===2&&$policy->get('lease_seconds')===120,'validated defaults');
check(rejects(fn()=>new WorkerPolicy(['processes'=>33]))&&rejects(fn()=>new WorkerPolicy(['memory_mb'=>1]))&&rejects(fn()=>new WorkerPolicy(['processes'=>true])),'invalid bounds rejected');
check(rejects(fn()=>new WorkerPolicy(['poll_min_ms'=>1000,'poll_max_ms'=>50]))&&rejects(fn()=>new WorkerPolicy(['lease_seconds'=>60]))&&rejects(fn()=>new WorkerPolicy(['shutdown_grace_seconds'=>1])),'cross-field timing invariants');
check($policy->idleDelay(0)===50&&$policy->idleDelay(50)===100&&$policy->idleDelay(800)===1000,'bounded exponential polling');
check(!$policy->exhausted(999,3599,255*1048576)&&$policy->exhausted(1000,0,0)&&$policy->exhausted(0,3600,0)&&$policy->exhausted(0,0,256*1048576),'recycle at job, time or allocated-memory boundary');
check(!(new WorkerPolicy(['max_jobs'=>0,'max_seconds'=>0,'memory_mb'=>0]))->exhausted(100000,100000,1000000000),'zero disables individual recycle threshold');
$e=new PDOException('transaction timeout');$e->errorInfo=['25P04'];check(DatabaseFailure::reconnectable($e),'transaction timeout requires new DB session');
putenv('QUEUE_WORKER_PROCESSES=0');check(rejects(fn()=>(new \Cajeer\Logs\Kernel\Application($root))->boot()),'runtime validates worker policy');putenv('QUEUE_WORKER_PROCESSES');
$dir=sys_get_temp_dir().'/cl-worker-'.bin2hex(random_bytes(8));mkdir($dir,0700);$registry=new WorkerRegistry($dir);$proc=null;
try {
    check($registry->snapshot()['active']===0&&scandir($dir)===['.','..'],'read-only empty registry');
    $a=new WorkerRegistry($dir);$b=new WorkerRegistry($dir);$a->start(120);$b->start(150);
    check(count($registry->snapshot()['workers'])===2&&$registry->snapshot()['active']===0,'distinct starting workers are not healthy');
    $a->progress(7);$b->progress(9);$snap=$registry->snapshot();check($snap['active']===2&&count(array_unique(array_column($snap['workers'],'id')))===2,'independent progress markers');
    $file=$dir.'/queue-worker-'.$snap['workers'][0]['id'].'.json';check((fileperms($file)&0777)===0600,'private per-process registry');
    $a->close();$b->close();check($registry->snapshot()['workers']===[],'graceful worker exit removes marker');
    $bad=$dir.'/queue-worker-'.str_repeat('f',32).'.json';file_put_contents($bad,'corrupt');chmod($bad,0600);check($registry->snapshot()['invalid']===1,'malformed marker rejected');unlink($bad);
    symlink('/etc/passwd',$bad);check($registry->snapshot()['invalid']===1,'linked marker rejected');unlink($bad);
    $old=['id'=>str_repeat('f',32),'host'=>WorkerRegistry::host(),'pool'=>null,'pid'=>1,'started_at'=>time()-90000,'last_success'=>time()-90000,'stale_after'=>120,'processed'=>1,'state'=>'active'];file_put_contents($bad,json_encode($old));chmod($bad,0600);
    check($registry->snapshot()['workers'][0]['status']==='stale','crashed worker stale');$registry->snapshot(true);check(!file_exists($bad),'old stale marker pruned');
    $spawn=function(string $mode)use($dir){return proc_open([PHP_BINARY,__FILE__,'pool',$dir,$mode],[0=>['file','/dev/null','r'],1=>['file',$dir.'/stdout','a'],2=>['file',$dir.'/stderr','a']],$pipes);};
    $live=function()use($registry){return array_values(array_filter($registry->snapshot()['workers'],fn($w)=>$w['status']==='ok'&&@posix_kill($w['pid'],0)));};
    $proc=$spawn('normal');check(is_resource($proc),'real pool process spawned');
    $initial=waitFor(fn()=>count($w=$live())===2?$w:false);check(count(array_unique(array_column($initial,'pid')))===2,'two independent supervised children');
    $victim=$initial[0];$peer=$initial[1];posix_kill($victim['pid'],SIGKILL);
    $replacement=waitFor(fn()=>count($w=$live())===2&&!in_array($victim['id'],array_column($w,'id'),true)?$w:false);
    check(in_array($peer['id'],array_column($replacement,'id'),true),'crashed child replaced without stopping peer');
    proc_terminate($proc,SIGTERM);$status=waitFor(fn()=>!($s=proc_get_status($proc))['running']?$s:false);check($status['exitcode']===0,'SIGTERM drains pool');proc_close($proc);$proc=null;
    check(count($live())===0,'all live child processes stopped');
    $proc=$spawn('ignore');$stubborn=waitFor(fn()=>count($w=$live())===2?$w:false);
    proc_terminate($proc,SIGTERM);$status=waitFor(fn()=>!($s=proc_get_status($proc))['running']?$s:false,5);check($status['exitcode']===1,'bounded grace escalates to SIGKILL');proc_close($proc);$proc=null;
    check(count($live())===0,'stubborn children reaped');
} finally {
    if (is_resource($proc)) {proc_terminate($proc,SIGTERM);proc_close($proc);}
    foreach (glob($dir.'/*')?:[] as $f) unlink($f);rmdir($dir);
}
echo "Worker runtime: $n checks passed\n";
