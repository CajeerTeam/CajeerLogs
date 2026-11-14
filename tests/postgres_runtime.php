<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,ConnectionFactoryInterface,ConnectionManager,DatabaseException,DatabaseFailure};
use Cajeer\Logs\Http\ErrorHandler;
$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot();
$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function pdoError(string $state,string $message='test'):PDOException { $e=new PDOException($message);$e->errorInfo=[$state,7,$message];return $e; }
function rejects(callable $f,string $message):bool {try{$f();}catch(Throwable $e){return str_contains($e->getMessage(),$message);}return false;}
foreach ([['08006','server closed the connection unexpectedly','connection_lost',true],['57P01','admin shutdown','connection_lost',true],['25P03','idle in transaction timeout','connection_lost',true],['HY000','SSL SYSCALL error: EOF detected','connection_lost',true],['08006','connection timed out','connect_timeout',true],['08006','password authentication failed for user','authentication',false],['28P01','bad password','authentication',false],['08006','server certificate verification failed','tls',false],['08006','database "missing" does not exist','configuration',false],['57014','statement timeout','statement_timeout',false],['55P03','lock timeout','lock_timeout',false],['40P01','deadlock','transaction_conflict',false],['53300','too many connections','capacity',true],['23505','duplicate value "tls"','query_error',false],['25006','read only','read_only',false]] as [$state,$message,$category,$retry]) {
 $error=pdoError($state,$message);check(DatabaseFailure::category($error)===$category&&DatabaseFailure::reconnectable($error)===$retry,'SQLSTATE '.$state.' '.$category);
}
$wrapped=new DatabaseException('safe',0,pdoError('08006','do-not-disclose'),'connection_lost');
check(DatabaseFailure::reconnectable($wrapped),'wrapped connection failure');
$r=ErrorHandler::response($wrapped,'test');check($r->status===503&&!str_contains($r->body,'do-not-disclose'),'safe unavailable HTTP response');
check(ErrorHandler::response(pdoError('23505','secret'),'test')->status===500,'query error is not retried or disguised');
for($i=1;$i<12;$i++){ $d=ConnectionFactory::retryDelay(250,$i);$cap=min(5000,250*(2**min(10,$i-1)));if($d<ceil($cap/2)||$d>$cap)throw new RuntimeException('bad retry jitter'); }
check(true,'exponential jitter bounded at 5 seconds');
$config=$app->config();$config->set('database.password','test-only-pass');$f=new ConnectionFactory($config);
foreach (['application_name'=>'a;dbname=evil','connect_attempts'=>'6','connect_retry_ms'=>'0','idle_transaction_timeout_ms'=>'0','keepalives_idle'=>'0','keepalives_interval'=>'121','keepalives_count'=>'11','tcp_user_timeout_ms'=>'999'] as $key=>$value) { $old=$config->get('database.'.$key);$config->set('database.'.$key,$value);check(rejects(fn()=>$f->make('pgsql'),$key==='application_name'?'DB_APPLICATION_NAME':$key),'invalid '.$key.' rejected before connection');$config->set('database.'.$key,$old); }
$oldUser=getenv('DB_MIGRATION_USERNAME');$oldPassword=getenv('DB_MIGRATION_PASSWORD');
try { putenv('DB_MIGRATION_USERNAME=ci_migration');putenv('DB_MIGRATION_PASSWORD');check(rejects(fn()=>$f->make('migration'),'Set both'),'partial migration credentials fail closed'); }
finally { foreach(['DB_MIGRATION_USERNAME'=>$oldUser,'DB_MIGRATION_PASSWORD'=>$oldPassword] as $key=>$value) putenv($value===false?$key:$key.'='.$value); }
// Test doubles only for state-machine unit tests; real pg_terminate_backend/worker in the integration suite.
final class ProbeStatement extends PDOStatement { public function __construct(){} public function closeCursor():bool{return true;} }
final class ProbePDO extends PDO {
 public bool $transaction=false;public bool $broken=false;public int $queries=0;
 public function __construct(){}
 public function inTransaction():bool{return $this->transaction;}
 public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {++$this->queries;if($this->broken)throw pdoError('08006','server closed the connection');return new ProbeStatement();}
}
final class ProbeFactory implements ConnectionFactoryInterface { public int $count=0;public bool $fail=false;public function make(string $connection):PDO {++$this->count;if($this->fail)throw new DatabaseException('unavailable',0,null,'connection_lost');return new ProbePDO();} }
$factory=new ProbeFactory();$manager=new ConnectionManager($factory);$a=$manager->connection();check($manager->connection()===$a&&$factory->count===1,'same healthy session reused');
$a->transaction=true;check(rejects(fn()=>$manager->connection(),'transaction boundary')&&$factory->count===1,'active transaction cannot be replaced');$a->transaction=false;
check($manager->probe()>=0&&$a->queries===1,'live probe executed');$a->broken=true;check(rejects(fn()=>$manager->probe(),'server closed'),'broken probe fails explicitly');$b=$manager->connection();check($b!==$a&&$factory->count===2,'failed session discarded and reopened');
$manager->invalidate();$factory->fail=true;check(rejects(fn()=>$manager->connection(),'unavailable'),'failed reconnect not hidden');$factory->fail=false;check($manager->connection()!==$b&&$manager->metrics()['connections']===3&&$manager->metrics()['failures']===2,'subsequent successful acquisition and metrics');
echo "PostgreSQL runtime: $n checks passed\n";
