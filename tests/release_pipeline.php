<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{StoredLogJson,LogQuery,LogRepository,PageCursor,LogExport,SearchRuntime};
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot();
$n=0;
function check(bool $value,string $name):void {global $n;if(!$value)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function failure(callable $fn,int $status):bool {try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
$precise='12345678901234567890.12345678901234567890';
$json='{"precise":'.$precise.',"tiny":1e-999,"large":1e999,"small":12.5,"integer":9223372036854775807,"big":9223372036854775808,"nested":[{"n":0.1234567890123456789}],"text":"literal 1234567890.123456789 and \"2.34\"","numericKey":{"12345678901234567890":true}}';
$parsed=StoredLogJson::decode($json);
check($parsed->precise===$precise,'historical decimal preserved as exact string');
check($parsed->tiny==='1e-999'&&$parsed->large==='1e999','underflow and overflow do not become zero or infinity');
check($parsed->small===12.5&&$parsed->integer===PHP_INT_MAX&&$parsed->big==='9223372036854775808','ordinary number and signed integer compatibility');
check($parsed->nested[0]->n==='0.1234567890123456789'&&$parsed->text==='literal 1234567890.123456789 and "2.34"','nested numeric literals only; quoted numbers unchanged');
check($parsed->numericKey->{'12345678901234567890'}===true,'numeric-looking object keys unchanged');
check(StoredLogJson::decode('{"n":1.23000000000000000000000000}')->n===1.23,'insignificant trailing zeroes retain ordinary numeric type');
check(StoredLogJson::decode('{"n":-0.000000000000000000001}')->n===-1e-21,'small representable decimal remains numeric');
check(StoredLogJson::decode('{"n":-9223372036854775809}')->n==='-9223372036854775809','negative oversized integer');
check(failure(fn()=>StoredLogJson::decode('"'.str_repeat('a',StoredLogJson::DETAIL_BYTES).'"'),413),'stored JSON bound');
$project='11111111-1111-4111-8111-111111111111';$id='22222222-2222-4222-8222-222222222222';$t='2026-01-01T00:00:00.000000Z';$q=LogQuery::parse(['project_id'=>$project]);$signer=new PageCursor(str_repeat('x',32),'reader');$state=$signer->open($q);
$row=static fn(string $num)=>['id'=>$id,'timestamp'=>$t,'payload'=>'{"id":"'.$id.'","timestamp":"'.$t.'","context":{"value":'.$num.'}}'];
$p=LogRepository::explorerPage($q,$signer,$state,[$row($precise)]);$changed=LogRepository::explorerPage($q,$signer,$state,[$row($precise.'1')]);
check($p['items'][0]['context']->value===$precise&&$p['page_digest']!==$changed['page_digest'],'explorer digest distinguishes precision-only changes');
check(str_contains(LogExport::csv($p['items']),$precise),'CSV preserves precise stored decimal');
class PipelineStmt extends PDOStatement {public function __construct(private array|false $row){}public function execute(?array $params=null):bool{return true;}public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;} public function closeCursor():bool{return true;}}
class PipelinePDO extends PDO {public string $sql='';public array|false $row=false;public bool $active=false;public function __construct(){}public function prepare(string $query,array $options=[]):PDOStatement|false{$this->sql=$query;return new PipelineStmt($this->row);}public function inTransaction():bool{return $this->active;}public function beginTransaction():bool{return $this->active=true;}public function commit():bool{$this->active=false;return true;}public function rollBack():bool{$this->active=false;return true;}public function exec(string $statement):int|false{return 0;}}
$db=new PipelinePDO();$repo=new LogRepository($db);$db->row=['payload'=>$row($precise)['payload']];
check($repo->find($q,$id)['context']->value===$precise&&str_contains($db->sql,'octet_length(payload)<=4194304')&&str_contains($db->sql,'project_id = CAST(:project_id AS uuid)'),'detail read uses bounded, tenant-scoped JSONB serialization');
$db->row=false;check($repo->find($q,$id)===null,'missing/unauthorized detail returns no row');
$db->row=['payload'=>null];check(failure(fn()=>$repo->find($q,$id),413),'oversized detail fails explicitly, not 404');
check(failure(fn()=>(new SearchRuntime($db,5))->run($q,function(){usleep(20000);return 'too late';}),503)&&!$db->active,'read-only budget includes response assembly and rolls back');
echo "Release pipeline: $n checks passed\n";
