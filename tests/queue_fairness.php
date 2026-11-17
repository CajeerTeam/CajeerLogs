<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\QueueFairness;
use Cajeer\Logs\Http\HttpException;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $b,string $msg):void {global $n;if(!$b)throw new RuntimeException('FAIL '.$msg);$n++;echo "PASS $msg\n";}
function invalid(callable $f):bool {try{$f();return false;}catch(InvalidArgumentException|LogicException){return true;}}
$policy=['weight'=>4,'max_jobs'=>100,'max_events'=>200,'max_bytes'=>1048576,'max_running_jobs'=>2];
check(QueueFairness::policy($policy)===$policy,'valid policy preserved');
foreach (['weight'=>[0,17,-1,'x',true],'max_jobs'=>[-1,100000001],'max_events'=>[-1,1000000001],
          'max_bytes'=>[-1,1099511627777],'max_running_jobs'=>[-1,1025]] as $field=>$bad) {
    foreach ($bad as $value) {
        $tmp=$policy;$tmp[$field]=$value;
        check(invalid(fn()=>QueueFairness::policy($tmp)),'reject invalid '.$field.'='.json_encode($value));
    }
}
check(invalid(fn()=>QueueFairness::policy(['weight'=>2])),'reject partial policy');
check(invalid(fn()=>QueueFairness::policy($policy+['injected'=>1])),'reject unknown policy');
check(QueueFairness::validProject('00112233-4455-6677-8899-aabbccddeeff'),'canonical UUID accepted');
foreach (['','../api','a','00112233-4455-6677-8899-aabbccddeeff\' OR TRUE','00112233-4455-6677-8899-aabbccddeeef '] as $v) check(!QueueFairness::validProject($v),'reject invalid UUID');
class FairPDO extends PDO {
    public bool $tx=true;
    public array $state=['active_jobs'=>0,'active_events'=>0,'active_bytes'=>0,'max_jobs'=>2,'max_events'=>5,'max_bytes'=>300];
    public array $updates=[];
    public function __construct(){}
    public function inTransaction():bool{return $this->tx;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new FairStatement($this,$query);}
}
class FairStatement extends PDOStatement {
    public array $parameters=[];
    public function __construct(private readonly FairPDO $db,private readonly string $sql){}
    public function execute(?array $params=null):bool { $this->parameters=$params??[];if(str_contains($this->sql,'rejected=rejected+1'))$this->db->updates[]='rejected';return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->db->state;}
}
$pdo=new FairPDO();$fair=new QueueFairness($pdo);$project='00112233-4455-6677-8899-aabbccddeeff';
check($fair->admit($project,2,100)===null,'admit within all dimensions');
$pdo->state['active_jobs']=2;
$fail=$fair->admit($project,1,50);check($fail instanceof HttpException&&$fail->error==='queue_project_full'&&$fail->status===503,'jobs hard cap');
$pdo->state['active_jobs']=1;$pdo->state['active_events']=5;
$fail=$fair->admit($project,1,50);check($fail instanceof HttpException&&str_contains($fail->getMessage(),'events'),'events hard cap');
$pdo->state['active_events']=1;$pdo->state['active_bytes']=280;
$fail=$fair->admit($project,1,50);check($fail instanceof HttpException&&str_contains($fail->getMessage(),'bytes'),'bytes hard cap');
check(count($pdo->updates)===3,'rejections accounted per project');
$pdo->tx=false;check(invalid(fn()=>$fair->admit($project,1,20)),'no admission outside transaction');
echo "Queue fairness policy: $n checks passed\n";
