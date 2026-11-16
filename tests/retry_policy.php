<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\RetryPolicy;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Kernel\Application;
$root=dirname(__DIR__);require $root.'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function rejects(array $values):bool {try{new RetryPolicy($values);return false;}catch(InvalidArgumentException){return true;}}
function sqlError(string $state):PDOException {$e=new PDOException('sensitive SQL parameter');$e->errorInfo=[$state,7,'sensitive SQL parameter'];return $e;}
$p=new RetryPolicy();
check($p->get('max_attempts')===8&&$p->delayBounds(1)===[4,6]&&$p->delayBounds(2)===[8,12],'default bounded jitter');
check($p->delayBounds(1000000)===[2700,3600],'large attempt saturates without overflow');
$values=[];for($i=0;$i<100;++$i)$values[]=$p->delay(4);
check(min($values)>=30&&max($values)<=50&&count(array_unique($values))>1,'actual random jitter bounded and nonconstant');
$d=new RetryPolicy(['base_seconds'=>3,'max_seconds'=>20,'jitter_percent'=>0]);
check($d->delay(1)===3&&$d->delay(2)===6&&$d->delay(5)===20,'zero jitter and custom exponential cap');
check((new RetryPolicy(['base_seconds'=>1,'max_seconds'=>1,'jitter_percent'=>100]))->delay(1)===1,'delay always at least one second');
foreach ([['max_attempts'=>0],['max_attempts'=>101],['max_attempts'=>true],['base_seconds'=>0],['max_seconds'=>4],['jitter_percent'=>101],['outage_base_ms'=>1000,'outage_max_ms'=>500],['outage_max_failures'=>0]] as $invalid)check(rejects($invalid),'reject '.json_encode($invalid));
$o=new RetryPolicy(['outage_base_ms'=>100,'outage_max_ms'=>500]);
foreach ([1=>[50,100],2=>[100,200],10=>[250,500]] as $attempt=>[$lo,$hi]) { $v=$o->outageDelay($attempt);check($v>=$lo&&$v<=$hi,'outage delay '.$attempt); }
foreach (['40001'=>'transaction_conflict','40P01'=>'transaction_conflict','55P03'=>'lock_timeout','57014'=>'statement_timeout','23514'=>'database_error'] as $state=>$reason)check(RetryPolicy::classify(sqlError((string)$state))===['action'=>'retry','reason'=>$reason],'retry '.$state);
foreach (['08006'=>'connection_lost','25P04'=>'connection_lost','53100'=>'capacity','25006'=>'read_only','42501'=>'database_configuration','42P01'=>'database_configuration','28P01'=>'authentication','0A000'=>'database_configuration'] as $state=>$reason)check(RetryPolicy::classify(sqlError((string)$state))===['action'=>'pause','reason'=>$reason],'pause '.$state);
check(RetryPolicy::classify(new DatabaseException('unknown commit',0,sqlError('08006'),'connection_lost'))['action']==='pause','uncertain commit never ordinary retry');
foreach ([new JsonException('secret'),new HttpException(403,'forbidden','secret'),new HttpException(413,'too_big','secret'),new HttpException(422,'invalid','secret')] as $e)check(RetryPolicy::classify($e)===['action'=>'dead','reason'=>'invalid_payload'],'permanent safe payload classification');
check(RetryPolicy::classify(new RuntimeException('secret'))===['action'=>'retry','reason'=>'processing_error'],'unknown failure keeps bounded retry');
$old=getenv('QUEUE_MAX_ATTEMPTS');try {putenv('QUEUE_MAX_ATTEMPTS=3');$a=new Application($root);$a->boot();check(RetryPolicy::fromConfig($a->config())->get('max_attempts')===3,'runtime configuration loaded');putenv('QUEUE_MAX_ATTEMPTS=0');$failed=false;try{(new Application($root))->boot();}catch(InvalidArgumentException){$failed=true;}check($failed,'invalid production policy fails bootstrap');}finally{putenv($old===false?'QUEUE_MAX_ATTEMPTS':'QUEUE_MAX_ATTEMPTS='.$old);}
// Exercise the actual continuous Worker/ConnectionManager boundary without PostgreSQL.
final class EmptyClaimStatement extends PDOStatement {
    public function __construct(){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return false;}
    public function closeCursor():bool{return true;}
}
final class RecoveryPDO extends PDO {
    private bool $active=false;public int $claims=0;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function beginTransaction():bool{$this->active=true;return true;}
    public function commit():bool{$this->active=false;return true;}
    public function rollBack():bool{$this->active=false;return true;}
    public function exec(string $statement):int|false{return 0;}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{if(str_contains($query,'FROM jobs'))++$this->claims;return new EmptyClaimStatement();}
}
final class RecoveryFactory implements \Cajeer\Logs\Database\ConnectionFactoryInterface {
    public int $calls=0;public array $sessions=[];
    public function make(string $connection):PDO {if(++$this->calls===1)throw sqlError('08006');return $this->sessions[]=new RecoveryPDO();}
}
$factory=new RecoveryFactory();$manager=new \Cajeer\Logs\Database\ConnectionManager($factory);$checks=0;$beats=0;$worker=null;
$worker=new \Cajeer\Logs\Queue\Worker($manager,1000,null,null,function()use(&$worker,&$beats){++$beats;$worker->stop();},new \Cajeer\Logs\Queue\WorkerPolicy(['max_seconds'=>3]),null,new RetryPolicy(['outage_base_ms'=>50,'outage_max_ms'=>100,'outage_max_failures'=>4]),function(PDO $pdo)use(&$checks){++$checks;return $checks>=2;});
$worker->run();
check($factory->calls===3&&$checks===2&&$beats===1&&$factory->sessions[0]->claims===0&&$factory->sessions[1]->claims===2,'continuous worker waits for recovery readiness before claiming');
echo "Retry policy: $n checks passed\n";
