<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{TransactionManager,DatabaseException,ConnectionManager,ConnectionFactoryInterface};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function pgerr(string $state):PDOException {$e=new PDOException('test secret');$e->errorInfo=[$state,0,'test secret'];return $e;}
function failure(callable $f):Throwable {try{$f();}catch(Throwable $e){return $e;}throw new RuntimeException('Expected failure');}
final class TxStatement extends PDOStatement { public function __construct(){} public function closeCursor():bool{return true;} }
final class TxPDO extends PDO {
    public bool $active=false; public bool $aborted=false; public bool $rollbackFailure=false; public ?Throwable $commitError=null; public array $calls=[];
    public function __construct(){}
    public function beginTransaction():bool{$this->calls[]='BEGIN';$this->active=true;$this->aborted=false;return true;}
    public function inTransaction():bool{return $this->active;}
    public function commit():bool{$this->calls[]='COMMIT';if($this->commitError){$e=$this->commitError;$this->commitError=null;$this->active=false;throw $e;}$this->active=false;return true;}
    public function rollBack():bool{$this->calls[]='ROLLBACK';if($this->rollbackFailure)throw pgerr('08006');$this->active=false;$this->aborted=false;return true;}
    public function exec(string $statement):int|false{$this->calls[]=$statement;if(str_starts_with($statement,'ROLLBACK TO SAVEPOINT'))$this->aborted=false;return 0;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$this->calls[]=$query;if($this->aborted)throw pgerr('25P02');return new TxStatement();}
}
$pdo=new TxPDO();$tx=new TransactionManager($pdo);
check($tx->run(fn()=>42)===42&&$pdo->calls===['BEGIN','SET TRANSACTION ISOLATION LEVEL READ COMMITTED READ WRITE','SET LOCAL synchronous_commit=on','SELECT 1','COMMIT'],'commit only after probe and durable setting');
$pdo->calls=[];check($tx->run(fn()=>7,isolation:'REPEATABLE READ',readOnly:true)===7&&in_array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY',$pdo->calls,true)&&!in_array('SET LOCAL synchronous_commit=on',$pdo->calls,true),'read-only isolation');
foreach(['40001','40P01'] as $state){$i=(object)['n'=>0];$pdo->calls=[];check($tx->run(function()use(&$i,$state){if(++$i->n===1)throw pgerr($state);return $i->n;},maxAttempts:3)===2&&count(array_filter($pdo->calls,fn($c)=>$c==='ROLLBACK'))===1,'full retry '.$state);}
foreach(['23505','55P03','57014','08006','40003','25P02'] as $state){$p=new TxPDO();$m=new TransactionManager($p);$i=(object)['n'=>0];$e=failure(fn()=>$m->run(function()use(&$i,$state){++$i->n;throw pgerr($state);},maxAttempts:3));check($e instanceof PDOException&&$i->n===1&&!$p->active,'no replay '.$state);}
$i=(object)['n'=>0];check(failure(fn()=>$tx->run(function()use(&$i){++$i->n;throw pgerr('40001');},maxAttempts:3)) instanceof PDOException&&$i->n===3,'retry bounded');
$i=(object)['n'=>0];check(failure(fn()=>$tx->run(function()use(&$i){++$i->n;throw pgerr('40P01');})) instanceof PDOException&&$i->n===1,'default no replay');
$pdo->calls=[];check($tx->run(function(TransactionManager $m){$v=$m->run(fn()=>2);try{$m->run(fn()=>throw new RuntimeException('business error'));}catch(RuntimeException){}return $v;})===2&&in_array('ROLLBACK TO SAVEPOINT cajeer_sp_2',$pdo->calls,true)&&in_array('RELEASE SAVEPOINT cajeer_sp_1',$pdo->calls,true),'nested success and rollback to savepoint');
$i=(object)['n'=>0];check($tx->run(function(TransactionManager $m)use(&$i){if(++$i->n===1){try{$m->run(fn()=>throw pgerr('40001'));}catch(PDOException){}}return $i->n;},maxAttempts:3)===2,'nested conflict forces root retry even if caught');
check(failure(fn()=>$tx->run(fn(TransactionManager $m)=>$m->run(fn()=>0,maxAttempts:2))) instanceof LogicException,'nested retry forbidden');
$pdo->beginTransaction();check(failure(fn()=>$tx->run(fn()=>1)) instanceof LogicException&&$pdo->active,'external transaction not owned or rolled back');$pdo->rollBack();
check(failure(fn()=>$tx->run(fn()=>0,isolation:'READ UNCOMMITTED')) instanceof InvalidArgumentException,'isolation validated');
check(failure(fn()=>$tx->run(fn()=>0,maxAttempts:6)) instanceof InvalidArgumentException,'retry limit validated');
$pdo->calls=[];check(failure(fn()=>$tx->run(function()use($pdo){$pdo->aborted=true;return 'fake success';})) instanceof PDOException&&!in_array('COMMIT',$pdo->calls,true),'swallowed SQL error cannot commit');
$pdo->commitError=pgerr('40001');$i=(object)['n'=>0];check($tx->run(function()use(&$i){return ++$i->n;},maxAttempts:3)===2,'known serialization failure during commit retried');
$pdo->commitError=pgerr('23503');check(failure(fn()=>$tx->run(fn()=>1,maxAttempts:3)) instanceof PDOException&&TransactionManager::usable($pdo),'deferred constraint failure is known abort');
$p=new TxPDO();$m=new TransactionManager($p);$p->commitError=pgerr('08006');$i=(object)['n'=>0];$e=failure(fn()=>$m->run(function()use(&$i){return ++$i->n;},maxAttempts:3));check($e instanceof DatabaseException&&$e->category==='connection_lost'&&$i->n===1&&!TransactionManager::usable($p)&&!str_contains($e->getMessage(),'secret'),'unknown commit not replayed and session poisoned');
check(failure(fn()=>$m->run(fn()=>1)) instanceof DatabaseException,'poisoned connection rejected');
$p=new TxPDO();$p->rollbackFailure=true;$m=new TransactionManager($p);$i=(object)['n'=>0];$e=failure(fn()=>$m->run(function()use(&$i){++$i->n;throw pgerr('40001');},maxAttempts:3));check($e instanceof DatabaseException&&$i->n===1&&!TransactionManager::usable($p),'rollback failure prevents replay');
$p=new TxPDO();$m=new TransactionManager($p);check(failure(fn()=>$m->run(function()use($p){$p->commit();return 1;})) instanceof LogicException&&!TransactionManager::usable($p),'callback may not take transaction ownership');
final class TxFactory implements ConnectionFactoryInterface {public int $calls=0;public function make(string $connection):PDO{++$this->calls;return new TxPDO();}}
$f=new TxFactory();$cm=new ConnectionManager($f);$p=$cm->connection();$p->commitError=pgerr('08006');failure(fn()=>(new TransactionManager($p))->run(fn()=>1));check($cm->connection()!==$p&&$f->calls===2,'connection manager replaces poisoned session');
for($i=1;$i<=20;$i++){ $d=TransactionManager::retryDelay($i);$cap=min(250,10*(2**min(5,$i-1)));if($d<ceil($cap/2)||$d>$cap)throw new RuntimeException('retry jitter');}check(true,'jitter bounded');
$m=TransactionManager::metrics();check($m['commits']>0&&$m['retries']>0&&$m['conflicts']>0&&$m['uncertain_commits']>0&&$m['cleanup_failures']>0&&$m['duration_seconds']>=0,'local metrics reflect actual operations');
echo "Transaction management: $n checks passed\n";
