<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\BackpressurePolicy;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Config\ConfigRepository;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;function check(bool $yes,string $name):void {global $n;if(!$yes)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}
final class CapacityPDO extends PDO {
    public array $counts=['active_jobs'=>0,'active_events'=>0,'active_bytes'=>0,'admission_paused'=>false];
    public array $rejected=['jobs'=>0,'events'=>0,'bytes'=>0,'paused'=>0];
    public array $sql=[];public bool $transaction=true;public ?int $measuredBytes=null;
    public function __construct(){}
    public function inTransaction():bool{return $this->transaction;}
    public function exec(string $statement):int|false{
        $this->sql[]=$statement;
        if(str_contains($statement,'admission_paused=true'))$this->counts['admission_paused']=true;
        if(str_contains($statement,'admission_paused=false'))$this->counts['admission_paused']=false;
        foreach($this->rejected as $k=>$value)if(str_contains($statement,'rejected_'.$k.'=rejected_'.$k.'+1'))$this->rejected[$k]++;
        return 1;
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$this->sql[]=$query;return new CapacityStatement($this,$query);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->sql[]=$query;return new CapacityStatement($this,$query);}
}
final class CapacityStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private CapacityPDO $db,private string $query){}
    public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->db->counts;}
    public function fetchColumn(int $column=0):mixed{return $this->db->measuredBytes??strlen($this->params['body']??'');}
}
function admitted(BackpressurePolicy $policy, CapacityPDO $pdo,int $events=1,int $bytes=100):?HttpException {
    $pdo->measuredBytes=$bytes;
    $error=$policy->admit($pdo,$events,'{"events":[]}');
    if($error===null){$pdo->counts['active_jobs']++;$pdo->counts['active_events']+=$events;$pdo->counts['active_bytes']+=$bytes;$policy->accepted($pdo);}
    return $error;
}
$defaults=new BackpressurePolicy();check($defaults->limits()===['max_jobs'=>1000,'max_events'=>100000,'max_bytes'=>536870912,'high_watermark_percent'=>85,'low_watermark_percent'=>60],'default policy');
foreach([[0,0,0,85,60],[1,-1,0,85,60],[1,0,-1,85,60],[1,0,0,0,0],[1,0,0,85,85],[1,0,0,101,60],[1,0,0,50,99],[100001,0,0,85,60],[1,1000000001,0,85,60],[1,0,1099511627777,85,60]] as $args){try{new BackpressurePolicy(...$args);$ok=false;}catch(InvalidArgumentException){$ok=true;}check($ok,'invalid policy rejected '.implode('/',$args));}
foreach(['QUEUE_MAX_EVENTS'=>'-1','QUEUE_MAX_BYTES'=>'1.2','QUEUE_HIGH_WATERMARK_PERCENT'=>'abc','QUEUE_LOW_WATERMARK_PERCENT'=>'85'] as $key=>$value){$cfg=new ConfigRepository(['queue'=>['max_jobs'=>100,'max_events'=>'0','max_bytes'=>'0','high_watermark_percent'=>'85','low_watermark_percent'=>'60']]);$map=['QUEUE_MAX_EVENTS'=>'max_events','QUEUE_MAX_BYTES'=>'max_bytes','QUEUE_HIGH_WATERMARK_PERCENT'=>'high_watermark_percent','QUEUE_LOW_WATERMARK_PERCENT'=>'low_watermark_percent'];$cfg->set('queue.'.$map[$key],$value);try{BackpressurePolicy::fromConfig($cfg);$ok=false;}catch(InvalidArgumentException){$ok=true;}check($ok,'invalid config '.$key);}
$policy=new BackpressurePolicy(10,0,0,80,50);$db=new CapacityPDO();
for($i=0;$i<8;$i++)check(admitted($policy,$db)===null,'job admitted '.($i+1));
check($db->counts['admission_paused']&&$db->counts['active_jobs']===8,'high watermark latches after accepted job');
$denied=admitted($policy,$db);check($denied instanceof HttpException&&$denied->status===503&&$denied->error==='queue_full'&&$denied->headers['Retry-After']==='5'&&$db->rejected['paused']===1,'paused request denied and counted');
$db->counts['active_jobs']=6;check(admitted($policy,$db) instanceof HttpException&&$db->counts['admission_paused'],'hysteresis still paused between thresholds');
$db->counts['active_jobs']=5;check(admitted($policy,$db)===null&&!$db->counts['admission_paused'],'admission resumes at low and accepts before high');
$db2=new CapacityPDO();$p2=new BackpressurePolicy(100,4,0,85,60);check(admitted($p2,$db2,3)===null&&!$db2->counts['admission_paused'],'event quota first group');check(admitted($p2,$db2,2) instanceof HttpException&&$db2->rejected['events']===1&&$db2->counts['active_events']===3,'event hard cap rejects before insert');check(admitted($p2,$db2,1)===null&&$db2->counts['admission_paused'],'event high watermark latches');
$db3=new CapacityPDO();$p3=new BackpressurePolicy(100,0,512,90,40);check(admitted($p3,$db3,1,400)===null,'JSONB size admitted');check(admitted($p3,$db3,1,200) instanceof HttpException&&$db3->rejected['bytes']===1,'byte hard cap');check(count(array_filter($db3->sql,fn($sql)=>str_contains($sql,'octet_length(CAST(:body AS jsonb)::text)')))>=2,'actual JSONB byte measurement SQL');
$db4=new CapacityPDO();$p4=new BackpressurePolicy(2,0,0,100,50);check(admitted($p4,$db4)===null&&admitted($p4,$db4)===null,'hard capacity reached exactly');$db4->counts['admission_paused']=false;check(admitted($p4,$db4) instanceof HttpException&&$db4->rejected['jobs']===1&&$db4->counts['admission_paused'],'hard job cap even if latch resets');
$db5=new CapacityPDO();$db5->transaction=false;try{$p4->admit($db5,1,'{}');$ok=false;}catch(LogicException){$ok=true;}check($ok,'no autocommit admission');
$db6=new CapacityPDO();$db6->counts['active_events']=70;$db6->counts['active_bytes']=1000;$db6->counts['active_jobs']=8;$db6->counts['admission_paused']=true;$policy3=new BackpressurePolicy(10,100,2000,80,60);$db6->counts['active_jobs']=5;check(admitted($policy3,$db6,1,1) instanceof HttpException,'any dimension above low holds latch');$db6->counts['active_events']=60;check(admitted($policy3,$db6,1,1)===null,'all dimensions drained resumes admission');
check(!array_filter($db6->sql,fn($sql)=>str_contains($sql,'count(*) FROM jobs')),'no queue scan during admission');
echo "Queue backpressure: $n checks passed\n";
