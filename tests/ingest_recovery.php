<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueFairness};
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function verifyRecovery(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo 'PASS '.$name.PHP_EOL;}
$db=new class extends PDO {
    public function __construct(){}
    public function inTransaction():bool{return false;}
};
$driver=new PostgresQueueDriver($db);
foreach([0,-1,1001,10000000] as $limit){
    try{$driver->recoverExpired($limit);$failed=false;}catch(InvalidArgumentException){$failed=true;}
    verifyRecovery($failed,'unbounded/invalid recovery limit rejected: '.$limit);
}
try{(new QueueFairness($db))->selectExpiredProject();$failed=false;}catch(LogicException){$failed=true;}
verifyRecovery($failed,'recovering a project requires an active transaction');
$help=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/cajeer').' --help 2>&1');
verifyRecovery(is_string($help)&&str_contains($help,'queue:recover'),'operator command appears in real CLI help');
echo "Ingest recovery unit: $n checks passed\n";
