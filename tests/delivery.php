<?php
declare(strict_types=1);
use Cajeer\Logs\Log\DeliveryRepository as D;
use Cajeer\Logs\Security\IngestPrincipal;
use Cajeer\Logs\Database\{DatabaseException,MigrationSql};
use Cajeer\Logs\Http\HttpException;
require dirname(__DIR__).'/core/bootstrap.php';
final class DeliveryStatement extends PDOStatement {
    public function __construct(private DeliveryPDO $db,private string $sql) {}
    public function execute(?array $params=null): bool { $this->db->calls[]=[$this->sql,$params]; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->db->root;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->db->rows;}
    public function fetchColumn(int $column=0): mixed {return $this->db->owner;}
}
final class DeliveryPDO extends PDO {
    public bool $active=true; public array $calls=[],$rows=[];public array|false $root=false;public mixed $owner=false;
    public function __construct(){}
    public function inTransaction(): bool{return $this->active;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new DeliveryStatement($this,$query);}
}
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function fails(callable $f,string $class,?int $status=null):bool{try{$f();}catch(Throwable $e){return $e instanceof $class&&($status===null||$e->status===$status);}return false;}
$t='11111111-1111-4111-8111-111111111111';$p='22222222-2222-4222-8222-222222222222';$s='33333333-3333-4333-8333-333333333333';$other='44444444-4444-4444-8444-444444444444';
$principal=IngestPrincipal::fromIdentity(['kind'=>'token','id'=>$t,'token'=>['id'=>$t,'project_id'=>$p,'source_id'=>$s,'scopes'=>['logs:write']]]);
$rootHash=hash('sha256','root');$master=hash('sha256','master');$hash=D::digest('{"x":1}',$rootHash,$master);$legacy=hash('sha256','legacy');$key=hash('sha256','operation');
check($hash===D::digest('{"x":1}',$rootHash,$master)&&strlen($hash)===64,'stable exact-byte digest');
check($hash!==D::digest('{"x":1} ',$rootHash,$master),'different byte representation conflicts');
check($hash!==D::digest('{"x":1}',hash('sha256','other'),$master),'independent credential namespace');
check($hash!==D::digest('{"x":1}',$rootHash,hash('sha256','other master')),'master domain separation');
check(D::retention(86400)===86400&&D::retention(2592000)===2592000&&D::RETENTION_SECONDS===604800,'bounded retention and default');
foreach([0,86399,2592001] as $v)check(fails(fn()=>D::retention($v),InvalidArgumentException::class),'invalid retention '.$v);
$db=new DeliveryPDO();$repo=new D($db);$db->active=false;
check(fails(fn()=>$repo->namespace($principal),LogicException::class),'namespace needs a transaction');$db->active=true;
check(fails(fn()=>$repo->namespace($principal),DatabaseException::class),'unknown namespace fails closed');
$db->root=['id'=>$other,'token_hash'=>$rootHash];check($repo->namespace($principal)===$db->root,'root resolved using token and project');
check($repo->lookup($principal,$other,$key,$hash,$legacy,false)===null,'absent operation');
$sync=['mode'=>'sync','token_id'=>$other,'request_hash'=>$legacy,'delivery_digest'=>$hash,'ids'=>json_encode([$s]),'job_id'=>null,'status'=>null,'delivery_count'=>null];$db->rows=[$sync];
check($repo->lookup($principal,$other,$key,$hash,$legacy,false)===['event_ids'=>[$s],'received'=>1],'rotation sync replay original IDs');
check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,$legacy,true),HttpException::class,409),'cross-mode conflict');
check(fails(fn()=>$repo->lookup($principal,$other,$key,str_repeat('a',64),$legacy,false),HttpException::class,409),'different request bytes conflict');
$db->rows=[$sync,$sync];check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,$legacy,false),HttpException::class,409),'ambiguous historic key fails closed');
$db->rows=[array_replace($sync,['delivery_digest'=>null])];check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,$legacy,false),HttpException::class,409),'legacy cross-token hash cannot be verified');
$db->rows=[array_replace($sync,['delivery_digest'=>null,'token_id'=>$t])];check($repo->lookup($principal,$other,$key,$hash,$legacy,false)['event_ids']===[$s],'legacy owner retains replay');
check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,str_repeat('b',64),false),HttpException::class,409),'legacy hash still enforced');
$db->rows=[array_replace($sync,['ids'=>json_encode([$s,$s])])];check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,$legacy,false),DatabaseException::class),'duplicate IDs in durable receipt not acknowledged');
$db->rows=[array_replace($sync,['mode'=>'async','job_id'=>'7','status'=>'cancelled','delivery_count'=>2])];check($repo->lookup($principal,$other,$key,$hash,$legacy,true)===['job_id'=>'7','status'=>'cancelled','replayed'=>true,'received'=>2],'cancelled job preserves original count and identity');
$db->rows=[array_replace($db->rows[0],['delivery_count'=>101])];check(fails(fn()=>$repo->lookup($principal,$other,$key,$hash,$legacy,true),DatabaseException::class),'corrupted job count fails closed');
check(D::legacyCount('{"events":[{},{}]}')===2&&D::legacyCount('{"message":"x"}')===1,'legacy count from already authenticated bytes');
$db->owner=$other;check($repo->jobOwner($principal,'7')===$other,'resolve family job owner');
$db->owner=false;check($repo->jobOwner($principal,'8')===null,'unknown or foreign job not resolved');
[$sql,$params]=end($db->calls);check(str_contains($sql,'j.project_id=:project')&&str_contains($sql,'t.source_id IS NOT DISTINCT FROM')&&str_contains($sql,'j.delivery_digest IS NOT NULL')&&$params['project']===$p&&$params['source']===$s,'polling scope and historical ownership SQL');
$m=MigrationSql::parse(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0014_delivery_idempotency.sql'));check($m['mode']==='transactional'&&count($m['statements'])===6,'atomic lineage migration parsed');
echo "Delivery guarantees: $n checks passed\n";
