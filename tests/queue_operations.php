<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\QueueOperations;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); $n++; echo "PASS $label\n"; }
function reject(callable $work):bool { try{$work();return false;}catch(InvalidArgumentException|LogicException|RuntimeException){return true;} }
foreach ([null,'','maintenance','Recover queue',str_repeat('a',200)] as $reason)
    check(QueueOperations::reason($reason)===($reason===''?null:$reason),'safe operator reason');
foreach (["\n","a\t","\0",str_repeat('a',201),false,1,[],"foo\rbar"] as $reason)
    check(reject(fn()=>QueueOperations::reason($reason)),'unsafe reason rejected');
foreach ([0,1,120,3600,'0','30','3600'] as $value)
    check(QueueOperations::duration($value)===(int)$value,'bounded drain timeout accepted');
foreach ([-1,3601,'-1','3601','1.2','1e2',' 2','02',null,true,'',[]] as $value)
    check(reject(fn()=>QueueOperations::duration($value)),'bad drain timeout rejected');
class ProbePDO extends PDO {
    public bool $tx=false;
    public mixed $paused='0';
    public function __construct() {}
    public function inTransaction():bool {return $this->tx;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        if (!str_contains($query,'FOR SHARE')) throw new RuntimeException('Unexpected query.');
        return new ProbeStatement($this->paused);
    }
}
class ProbeStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
$db=new ProbePDO();check(reject(fn()=>QueueOperations::canClaim($db)),'dispatch lock must be inside transaction');
$db->tx=true;
check(QueueOperations::canClaim($db),'active dispatch permits claim');
$db->paused='1';check(!QueueOperations::canClaim($db),'paused dispatch rejects claim');
$db->paused=false;check(reject(fn()=>QueueOperations::canClaim($db)),'missing control row fails closed');
check(class_exists(QueueOperations::class),'real queue operation service autoloads');
echo "Queue Operations: $n checks passed\n";
