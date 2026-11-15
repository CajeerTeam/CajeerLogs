<?php
declare(strict_types=1);
use Cajeer\Logs\Database\Repository\SourceRepository as Sources;
use Cajeer\Logs\Http\{HttpException,Request};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$app->config()->set('app.env','test');
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function fails(callable $fn,int $status=422):bool{try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
check(Sources::changes(['name'=>'Worker','description'=>'','metadata'=>(object)[],'source_type'=>'nginx','retention_days'=>null,'max_body_bytes'=>null,'max_batch_events'=>null,'events_per_minute'=>null])===['name'=>'Worker','description'=>'','metadata'=>'{}','source_type'=>'nginx','retention_days'=>null,'max_body_bytes'=>null,'max_batch_events'=>null,'events_per_minute'=>null],'valid patch and inherited policies');
foreach(Sources::TYPES as $type)check(Sources::changes(['source_type'=>$type])['source_type']===$type,'source type '.$type);
foreach([[],['name'=>' '],['description'=>str_repeat('x',4097)],['metadata'=>[]],['metadata'=>(object)['large'=>str_repeat('x',8192)]],['source_type'=>'unknown'],['source_type'=>[]],['retention_days'=>0],['events_per_minute'=>'1'],['max_body_bytes'=>1048577],['max_batch_events'=>101],['slug'=>'changed'],['id'=>'changed'],['project_id'=>'changed'],['status'=>'active'],['last_seen_at'=>null],['accepted_events_total'=>5],['name'=>"x\0y"]] as $bad)check(fails(fn()=>Sources::changes($bad)),'reject field '.implode(',',array_keys($bad)));
$r=Sources::decode(['metadata'=>'{}','revision'=>2,'accepted_events_total'=>3,'max_body_bytes'=>'1024','retention_days'=>null]);check($r['metadata'] instanceof stdClass&&$r['revision']==='2'&&$r['accepted_events_total']==='3'&&$r['max_body_bytes']===1024,'stable response types');
$id='11111111-1111-4111-8111-111111111111';$path='/projects/'.$id.'/sources/'.$id;foreach(['PATCH '.$path,'POST '.$path.'/disable','POST '.$path.'/enable','POST '.$path.'/archive','POST '.$path.'/restore','GET '.$path.'/stats'] as $route){[$method,$url]=explode(' ',$route);check($app->handle(new Request($method,'/api/v1'.$url))->status===401,'protected '.$route);}
$repo=new Sources(new class extends PDO {public function __construct(){} public function inTransaction():bool{return false;}});try{$repo->admit($id,[$id=>1],100);throw new RuntimeException('admission without transaction');}catch(LogicException){check(true,'admission requires transaction');}
// Controlled PDO fixture exercises policy branches and query order without pretending to be PostgreSQL.
final class SourcePolicyPDO extends PDO {
    public array $calls=[]; public array $rows=[]; public mixed $quota=1;
    public function __construct() {}
    public function inTransaction():bool{return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new SourcePolicyStatement($this,$query);}
}
final class SourcePolicyStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private SourcePolicyPDO $db,private string $query){}
    public function execute(?array $params=null):bool{$this->params=$params??[];$this->db->calls[]=[$this->query,$this->params];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{$rows=[];foreach(json_decode($this->params['batch'],true,32,JSON_THROW_ON_ERROR) as $r){if(isset($this->db->rows[$r['id']]))$rows[]=$r+$this->db->rows[$r['id']];}return $rows;}
    public function rowCount():int{return count(json_decode($this->params['batch'],true,32,JSON_THROW_ON_ERROR));}
    public function fetchColumn(int $column=0):mixed{return $this->db->quota;}
}
$db=new SourcePolicyPDO();$repo=new Sources($db);$other='22222222-2222-4222-8222-222222222222';$active=['status'=>'active','max_body_bytes'=>null,'max_batch_events'=>null,'events_per_minute'=>null];$db->rows=[$id=>$active,$other=>$active];
$repo->admit($id,[$other=>1,$id=>2],100);check(array_column(json_decode($db->calls[0][1]['batch'],true),'id')===[$id,$other]&&str_contains($db->calls[0][0],'ORDER BY s.id FOR UPDATE')&&count($db->calls)===2,'source locks ordered deterministically');check(json_decode($db->calls[1][1]['batch'],true)[0]['events']===2&&str_contains($db->calls[1][0],'accepted_events_total'),'accepted counter uses source event count');
$db->calls=[];$db->rows[$id]['status']='disabled';check(fails(fn()=>$repo->admit($id,[$id=>1],100),409)&&count($db->calls)===1,'disabled admission rejects before counters');
$db->rows[$id]=array_replace($active,['max_body_bytes'=>1024]);check(fails(fn()=>$repo->admit($id,[$id=>1],1025),413),'source body limit enforced');
$db->rows[$id]=array_replace($active,['max_batch_events'=>1]);check(fails(fn()=>$repo->admit($id,[$id=>2],100),413),'source event limit enforced');
$db->rows[$id]=array_replace($active,['events_per_minute'=>1]);check(fails(fn()=>$repo->admit($id,[$id=>2],100),429),'batch exceeding quota rejected');
$db->quota=false;check(fails(fn()=>$repo->admit($id,[$id=>1],100),429),'shared quota refusal propagated');
$db->calls=[];$repo->recordPolicyRejection($id,[$id=>2]);check(str_contains($db->calls[0][0],'ORDER BY s.id FOR UPDATE')&&str_contains($db->calls[1][0],'rejected_events_total')&&json_decode($db->calls[1][1]['batch'],true)[0]['events']===2,'policy rejection telemetry separate from acceptance');
echo "Source Management: $n checks passed\n";
