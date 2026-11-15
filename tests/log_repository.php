<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{LogRepository,LogQuery,IngestEvent,Redactor};
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $v,string $name):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function failure(callable $f):Throwable {try{$f();}catch(Throwable $e){return $e;}throw new RuntimeException('Expected failure');}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$t='33333333-3333-4333-8333-333333333333';
$e=new IngestEvent('app','info','password=[redacted]',(object)['token'=>'raw','empty'=>(object)[]],'2026-01-01T00:00:00.000000Z',tags:['a','a']);
[$ids,$body]=LogRepository::encodeBatch([$e,$e],['app'=>$s]);$rows=json_decode($body,true,32,JSON_THROW_ON_ERROR);
check(count(array_unique($ids))===2 && array_column($rows,'id')===$ids,'UUIDs unique and receipt order preserved');
check($rows[0]['source_id']===$s&&$rows[0]['message']==='password=[redacted]'&&$rows[0]['context']['token']==='[redacted]'&&$rows[0]['tags']===['a'],'stored event normalized and remasked');
check((new Redactor())->text('password=[redacted]')==='password=[redacted]','mask idempotence');
foreach ([[],array_fill(0,101,$e),[1=>$e],['bad'],[$e]] as $i=>$events) {
    check(failure(fn()=>LogRepository::encodeBatch($events,$i===4?[]:['app'=>$s])) instanceof HttpException,'reject malformed batch '.$i);
}
check(failure(fn()=>LogRepository::encodeBatch([$e],['app'=>'bad'])) instanceof HttpException,'source UUID validated');
$bad=new IngestEvent('app','invalid','message',(object)[],'2026-01-01T00:00:00Z');
check(failure(fn()=>LogRepository::encodeBatch([$bad],['app'=>$s])) instanceof HttpException,'corrupted queued level rejected');
$big=new IngestEvent('app','info',str_repeat('x',16000),(object)['data'=>str_repeat('y',32000)],'2026-01-01T00:00:00Z');
check(failure(fn()=>LogRepository::encodeBatch(array_fill(0,100,$big),['app'=>$s])) instanceof HttpException,'normalized aggregate byte limit');
check(failure(fn()=>LogQuery::parse(['project_id'=>$p,'source_id'=>$s])->scoped($t)) instanceof HttpException,'source override rejected');
class RepoStatement extends PDOStatement {
    public array $params=[];
    public function __construct(private readonly RepoPDO $pdo,private readonly string $sql){}
    public function execute(?array $params=null):bool {$this->params=$params??[];$this->pdo->last=$this->params;return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if (str_contains($this->sql,'INSERT INTO log_events')) {
            $ids=array_column(json_decode($this->params['events'],true,32,JSON_THROW_ON_ERROR),'id');
            return match($this->pdo->result){'empty'=>[],'partial'=>[$ids[0]],'wrong'=>array_fill(0,count($ids),'00000000-0000-0000-0000-000000000000'),default=>array_reverse($ids)};
        }
        return array_shift($this->pdo->pages)??[];
    }
    public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,"current_setting('transaction_isolation')")?'read committed':false;}
    public function closeCursor():bool{return true;}
}
class RepoPDO extends PDO {
    public bool $active=true;public string $result='all';public string $sql='';public array $last=[];public array $pages=[];public array $calls=[];
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function prepare(string $query,array $options=[]):PDOStatement|false {$this->sql=$query;$this->calls[]=$query;return new RepoStatement($this,$query);}
}
$pdo=new RepoPDO();$repo=new LogRepository($pdo);$ids=$repo->storeBatch([$e,$e],$p,['app'=>$s],$t);
check($ids===array_column(json_decode($pdo->last['events'],true,32,JSON_THROW_ON_ERROR),'id'),'return request order regardless of database row order');
check(str_contains($pdo->calls[count($pdo->calls)-2],'pg_advisory_xact_lock_shared')&&str_contains($pdo->sql,'token_id,clock_timestamp()'),'writer fence precedes INSERT and received_at uses database clock');
check(str_contains($pdo->sql,'s.project_id=p.project_id')&&str_contains($pdo->sql,'t.source_id=s.id')&&str_contains($pdo->sql,"jsonb_typeof(t.scopes)='array'")&&str_contains($pdo->sql,'jsonb_array_length')&&str_contains($pdo->sql,'count(*) FROM eligible'),'all-or-nothing scope SQL');
foreach(['empty','partial','wrong'] as $result){$pdo->result=$result;check(failure(fn()=>$repo->storeBatch([$e,$e],$p,['app'=>$s],$t)) instanceof RuntimeException,'reject incomplete/mismatched DB receipt '.$result);}
$pdo->active=false;check(failure(fn()=>$repo->storeBatch([$e],$p,['app'=>$s],$t)) instanceof LogicException,'transaction required');
check(failure(fn()=>$repo->receipt($t,str_repeat('a',64),str_repeat('b',64))) instanceof LogicException,'receipt lookup requires transaction');
check(failure(fn()=>$repo->claimNonce($t,'nonce')) instanceof LogicException,'nonce claim requires transaction');
$pdo->active=true;
foreach ([[],['bad'],[$p,$p]] as $badIds) check(failure(fn()=>$repo->saveReceipt($t,str_repeat('a',64),str_repeat('b',64),$badIds)) instanceof \Cajeer\Logs\Database\DatabaseException,'invalid receipt IDs rejected');
check(LogRepository::metrics()['batch_attempts']===5&&LogRepository::metrics()['failed_batches']===4&&LogRepository::metrics()['rows_returned']===2,'metrics reflect SQL results, not commits');
$r=fn(string $id)=>['id'=>$id,'timestamp'=>'2026-01-01T00:00:00.000000Z','context'=>'{}','tags'=>'[]'];
$pdo->pages=[[$r($t),$r($s)],[$r($s),$r($p)],[$r($p)]];
$q=LogQuery::parse(['project_id'=>$p,'limit'=>'1']);
check(array_column(iterator_to_array($repo->iterate($q),false),'id')===[$t,$s,$p]&&$pdo->last['cursor_id']===$s,'bounded iteration follows cursor across equal timestamps');
$encoded=fn(string $id)=>['id'=>$id,'timestamp'=>'2026-01-01T00:00:00.000000Z','payload'=>json_encode(['id'=>$id,'timestamp'=>'2026-01-01T00:00:00.000000Z','context'=>(object)[],'tags'=>[]],JSON_THROW_ON_ERROR)];
$pdo->pages=[[$encoded($t),['payload'=>null]],[$encoded($s),['payload'=>null]],[$encoded($p)]];
check(array_column(iterator_to_array($repo->iterateBounded($q),false),'id')===[$t,$s,$p]&&$pdo->last['cursor_id']===$s&&str_contains($pdo->sql,'sum(octet_length(payload))'),'CLI stream uses byte-bounded keyset and actual last emitted row');
echo "Log repository: $n checks passed\n";
