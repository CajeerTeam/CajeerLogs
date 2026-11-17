<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{IngestBatch,IngestEvent,LogRepository};
use Cajeer\Logs\Queue\PostgresQueueDriver;
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $v,string $name):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function reject(callable $f,int $status):bool{try{$f();}catch(HttpException $e){return $e->status===$status;}return false;}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$t='33333333-3333-4333-8333-333333333333';
$e=new IngestEvent('app','INFO','password=secret',(object)['token'=>'secret','nested'=>(object)['empty'=>(object)[]],'number'=>1e300],'2026-01-01T01:00:00+01:00',tags:['a','a']);
$z=new IngestEvent('0','info','numeric',new stdClass(),'2026-01-01T00:00:00Z');
$body=IngestBatch::encodeJob([$e,$z,$e],['app'=>$s,'0'=>$t]);[$events,$sources]=IngestBatch::decodeJob($body);
check(count($events)===3&&array_map(fn($x)=>$x->source,$events)===['app','0','app'],'mixed batch preserves input order');
check($events[0]->level==='info'&&$events[0]->timestamp==='2026-01-01T00:00:00.000000Z'&&$events[0]->tags===['a'],'normalization at durable boundary');
check(!str_contains($body,'secret')&&$events[0]->context->token==='[redacted]'&&$events[0]->context->nested->empty instanceof stdClass,'redaction and JSON object fidelity');
check($events[0]->context->number===1e300,'large finite numeric context preserved');
check(json_decode($body)->sources instanceof stdClass&&$sources['0']===$t,'numeric slug serialized as object');
$legacy=json_encode(['events'=>[$z],'sources'=>[$t]],JSON_THROW_ON_ERROR);check(IngestBatch::decodeJob($legacy)[1]['0']===$t,'historical numeric source map accepted');
foreach ([[],[1=>$e],array_fill(0,101,$e),['invalid']] as $bad) check(reject(fn()=>IngestBatch::normalize($bad),422),'invalid batch rejected');
foreach ([[],['app'=>$s,'extra'=>$t],['app'=>'bad']] as $bad) check(reject(fn()=>IngestBatch::encodeJob([$e],$bad),422),'invalid source coverage rejected');
$base=json_decode($body);foreach (['message','context','tags','timestamp'] as $field) {$bad=clone $base;$bad->events=[clone $base->events[0]];unset($bad->events[0]->$field);check(reject(fn()=>IngestBatch::decodeJob(json_encode($bad)),422),'missing durable '.$field);}
foreach (['message'=>12,'service'=>false,'context'=>[],'tags'=>(object)[],'extra'=>'raw'] as $field=>$value) {$bad=json_decode($body);$bad->events[0]->$field=$value;check(reject(fn()=>IngestBatch::decodeJob(json_encode($bad)),422),'invalid durable '.$field);}
check(reject(fn()=>IngestBatch::decodeJob('{}'),422)&&reject(fn()=>IngestBatch::decodeJob(str_repeat(' ',IngestBatch::JOB_DECODE_BYTES+1)),413),'shape and raw decoding bounds');
$big=new IngestEvent('app','info',str_repeat('x',16000),(object)['data'=>str_repeat('y',32000)],'2026-01-01T00:00:00Z');
check(reject(fn()=>IngestBatch::encodeJob(array_fill(0,50,$big),['app'=>$s]),413),'durable compact byte limit');
check(reject(fn()=>IngestBatch::checkSize(array_fill(0,50,$big),IngestBatch::JOB_BYTES),413),'HTTP normalized size bound before transaction');
[$ids,$sql]=LogRepository::encodeBatch(array_fill(0,50,$big),['app'=>$s]);check(count($ids)===50&&strlen($sql)<IngestBatch::SQL_BYTES,'separate sync SQL limit retained');
$valid=IngestBatch::encodeJob([$e],['app'=>$s]);$decimal=str_replace('1.0e+300','1'.str_repeat('0',300),$valid);check(IngestBatch::decodeJob($decimal)[0][0]->context->number===1e300,'JSONB decimal expansion decoded as numeric');
// Controlled PDO fixture verifies admission order and permanent/stale worker paths.
final class BatchPDO extends PDO {
    public bool $active=false;public array $calls=[];public mixed $existing=false;public string $payload='{}';public bool $live=true;public string $outcome='';public mixed $insertId='7';public string $scope='ok';public mixed $activeJobs=0;public ?PDOException $readError=null;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function beginTransaction():bool{$this->active=true;return true;}
    public function commit():bool{$this->active=false;return true;}
    public function rollBack():bool{$this->active=false;return true;}
    public function exec(string $statement):int|false{$this->calls[]=[$statement,[]];return 0;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new BatchStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$s=$this->prepare($query);$s->execute();return $s;}
}
final class BatchStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private BatchPDO $db,private string $sql){}
    public function execute(?array $params=null):bool{$this->params=$params??[];$this->db->calls[]=[$this->sql,$this->params];if($this->db->readError!==null&&str_contains($this->sql,'CASE WHEN payload_bytes'))throw $this->db->readError;if(str_contains($this->sql,'SET outcome=:outcome'))$this->db->outcome=$params['outcome'];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        if(str_contains($this->sql,'request_hash FROM jobs'))return $this->db->existing;
        if(str_contains($this->sql, 'FROM queue_accounting'))return $this->db->activeJobs===false?false:['active_jobs'=>$this->db->activeJobs,'active_events'=>0,'active_bytes'=>0,'admission_paused'=>false];
        return ['id'=>'7','project_id'=>'11111111-1111-4111-8111-111111111111','token_id'=>'33333333-3333-4333-8333-333333333333','attempts'=>1,'max_attempts'=>8];
    }
    public function fetchColumn(int $column=0):mixed{return match(true){str_contains($this->sql,"current_setting('transaction_isolation')")=>'read committed',str_contains($this->sql,'WITH locked AS MATERIALIZED')=>$this->db->scope,str_contains($this->sql,'lease_until>')=>$this->db->live,str_contains($this->sql,'CASE WHEN payload_bytes')=>$this->db->payload,str_contains($this->sql,'SELECT active_jobs FROM queue_accounting')=>$this->db->activeJobs,str_contains($this->sql,'INSERT INTO jobs')=>$this->db->insertId,default=>0};}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
    public function closeCursor():bool{return true;}
    public function rowCount():int{return 1;}
}
$db=new BatchPDO();$q=new PostgresQueueDriver($db);$db->active=true;$key=str_repeat('a',64);$hash=str_repeat('b',64);$admitted=0;
$admit=function()use(&$admitted){++$admitted;return null;};
check($q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],$admit)['job_id']==='7'&&$admitted===1,'new job invokes admission once');
check(count(array_filter($db->calls,fn($call)=>str_contains($call[0],'INSERT INTO jobs')&&str_contains($call[0],'octet_length(b.body::text)')&&$call[1]['limit']===IngestBatch::JOB_DECODE_BYTES))===1,'enqueue checks actual JSONB decoding size');
check(str_contains(json_encode($db->calls),'LOCK TABLE jobs')&&str_contains(json_encode($db->calls),'FOR UPDATE')&&!str_contains(json_encode($db->calls),'count(*) FROM jobs'),'enqueue locks counter without queue scan');
$db->existing=['id'=>'7','status'=>'completed','request_hash'=>$hash];check($q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],$admit)['replayed']&&$admitted===1,'replay skips admission and capacity');
$db->existing=false;$db->calls=[];check(reject(fn()=>$q->enqueue($t,$p,$key,$hash,array_fill(0,50,$big),['app'=>$s],$admit),413)&&$admitted===1&&count($db->calls)===1,'invalid new job rejected before admission and capacity lock');
$db->calls=[];$denial=new HttpException(429,'source_quota_exceeded','denied');check($q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],fn()=>$denial)===$denial&&!str_contains(json_encode($db->calls),'INSERT INTO jobs'),'policy denial bypasses enqueue');
$db->calls=[];$db->scope='forbidden';check(reject(fn()=>$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],$admit),403)&&$admitted===1&&count($db->calls)===2,'direct scope denial occurs before admission and capacity');
$db->calls=[];$db->scope='unauthorized';check(reject(fn()=>$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],$admit),401)&&$admitted===1,'inactive direct credential cannot enqueue');$db->scope='ok';
$db->calls=[];$db->activeJobs=1000;check(($denied=$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s])) instanceof HttpException && $denied->status===503 && !str_contains(json_encode($db->calls),'INSERT INTO jobs'),'full accounting row rejects before INSERT');
$db->calls=[];$quotaCalls=0;$tooFull=$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s],function()use(&$quotaCalls){$quotaCalls++;return null;});
check($tooFull instanceof HttpException&&$quotaCalls===0,'full queue does not consume project/source quota');
$db->activeJobs=false;try{$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s]);throw new LogicException('missing accounting accepted');}catch(RuntimeException $error){check($error->getMessage()==='Queue accounting is missing.','missing accounting fails closed');}
$db->activeJobs=0;$db->insertId=false;check(reject(fn()=>$q->enqueue($t,$p,$key,$hash,[$e],['app'=>$s]),413),'JSONB expansion refusal does not return accepted job');
$db->active=false;$db->calls=[];$claim=['id'=>'7','lease_id'=>$s];check($q->process($claim)==='dead'&&$db->outcome==='dead','invalid durable payload immediately dead-lettered');
$db->live=false;$db->calls=[];check($q->process($claim)==='stale'&&!str_contains(json_encode($db->calls),'payload_bytes<=CAST'),'expired lease checked before fetching payload');
$db->live=true;
foreach (['55P03'=>'lock_timeout','57014'=>'statement_timeout','23514'=>'database_error'] as $state=>$reason) {
    $db->calls=[];$db->readError=new PDOException('secret SQL parameters');$db->readError->errorInfo=[$state,7,'secret'];
    check($q->process($claim)==='retry'&&$db->outcome==='retry'&&str_contains(json_encode($db->calls),$reason)&&!str_contains(json_encode($db->calls),'secret SQL parameters'),'known rollback classified '.$state);
}
foreach (['42501','08006'] as $state) {
    $db->calls=[];$db->readError=new PDOException('secret SQL parameters');$db->readError->errorInfo=[$state,7,'secret'];$caught=false;
    try{$q->process($claim);}catch(PDOException){$caught=true;}
    check($caught&&!str_contains(json_encode($db->calls),'SET outcome=:outcome'),'infrastructure failure leaves durable claim '.$state);
}
echo "Batch ingestion: $n checks passed\n";
