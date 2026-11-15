<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{RedactionPolicy,Redactor,IngestEvent,LogRepository,RedactionPolicies};
use Cajeer\Logs\Http\{HttpException,Request};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$app->config()->set('app.env','test');
$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejects(callable $f,int $status):bool{try{$f();}catch(HttpException $e){return $e->status===$status;}return false;}
function rule(string $type,string $value):object{return (object)compact('type','value');}
$ctx=(object)['customer_id'=>123,'nested'=>[(object)['Email'=>'user@example.test','safe'=>true],(object)['Email'=>'other@example.test']],'a/b'=>(object)['~x'=>'hidden'],'account'=>(object)['code'=>42],'safe'=>(object)[],'token'=>'hidden'];
$event=new IngestEvent('app','info','example JWT eyJabc.eyJdef.signature', $ctx,'2026-01-01T00:00:00Z','service','host','trace','request',['secret-tag','safe']);
$rules=[rule('path','/context/customer_id'),rule('key','email'),rule('path','/context/a~1b/~0x'),rule('path','/context/account'),rule('path','/trace_id'),rule('regex','secret-tag')];
$policy=new RedactionPolicy($rules);$masked=$policy->apply($event);$m=RedactionPolicy::MASK;
check($masked->context->customer_id===$m&&$masked->context->account===$m,'path masks number and entire subtree');
check($masked->context->nested[0]->Email===$m&&$masked->context->nested[1]->Email===$m&&$masked->context->nested[0]->safe===true,'case insensitive exact key through arrays');
check($masked->context->{'a/b'}->{'~x'}===$m,'JSON pointer escapes');
check($masked->traceId===$m&&$masked->requestId==='request'&&$masked->source==='app'&&$masked->level==='info','only permitted content masked');
check($masked->tags===[$m,'safe']&&$masked->context->safe instanceof stdClass,'regex masks whole string and keeps object shape');
check($masked->context->token===$m&&!str_contains($masked->message,'eyJabc'),'builtin JWT and secret fields always masked');
check(json_encode($masked)===json_encode($policy->apply($masked)),'idempotent policy application');
check($ctx->customer_id===123&&$ctx->nested[0]->Email==='user@example.test','input objects never mutated');
check((new RedactionPolicy([rule('path','/context/nested/*/safe')]))->apply($event)->context->nested[0]->safe===$m,'wildcard path includes array elements');
foreach ([rule('path','/source'),rule('path','/context'),rule('path','/context/a~2'),rule('path','/message/x'),rule('regex','('),rule('regex','(?i)abc'),rule('regex','(*LIMIT_MATCH=999999)x'),rule('regex','a~b'),rule('unknown','x'),rule('key',''),rule('key',str_repeat('x',257)),(object)['type'=>'key','value'=>'x','extra'=>1]] as $r) check(rejects(fn()=>RedactionPolicy::validate([$r]),422),'invalid policy safely rejected');
check(rejects(fn()=>RedactionPolicy::validate(array_fill(0,17,rule('key','id'))),422),'per-scope rule limit');
$evil=new RedactionPolicy([rule('regex','(a+)+$')]);$bad=new IngestEvent('app','info',str_repeat('a',10000).'!',new stdClass(),'2026-01-01T00:00:00Z');
check(rejects(fn()=>$evil->apply($bad),503),'regex limit fails closed without leaking value');
try { $evil->apply($bad); } catch(HttpException $e) { $response=\Cajeer\Logs\Http\ErrorHandler::response($e,'test'); check($response->status===503&&$response->header('Retry-After')==='5'&&json_decode($response->body,true)['error']==='redaction_failed'&&!str_contains($response->body,'aaaa'),'safe HTTP redaction error and retry header'); }
$redactor=new Redactor();
check($redactor->text("-----BEGIN PRIVATE KEY-----\nprivate-data\n-----END PRIVATE KEY-----") === $m,'multiline PEM masked');
check(!str_contains($redactor->text('postgresql://alice:pw123@localhost/db'),'pw123'),'database DSN masked');
check(!str_contains($redactor->text('sk_'.str_repeat('a',64)),str_repeat('a',64)),'signing credential masked');
$patches=LogRepository::contextPatches($ctx,$masked->context);
check(count($patches)===6&&array_column($patches,'path')[0]===['customer_id'],'history patches only changed leaves');
check(LogRepository::contextPatches($masked->context,$masked->context)===[],'history rerun no changes');
// Controlled PostgreSQL statement fixture: verify repository actually combines scopes.
final class PrivacyPDO extends PDO {
    public array $rows=[]; public array $queries=[]; public bool $active=true;
    public function __construct(){} public function inTransaction():bool{return $this->active;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->queries[]=$query;return new PrivacyStatement($this,$query);}
}
final class PrivacyStatement extends PDOStatement {
    public function __construct(private PrivacyPDO $db,private string $sql){}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,'current_setting')?'read committed':false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->db->rows;}
    public function closeCursor():bool{return true;}
}
$p='11111111-1111-4111-8111-111111111111';$a='22222222-2222-4222-8222-222222222222';$b='33333333-3333-4333-8333-333333333333';
$db=new PrivacyPDO();$row=fn($key,$rules)=>['key'=>$key,'value'=>json_encode(['revision'=>1,'rules'=>$rules])];
$db->rows=[$row('redaction.system',[rule('key','email')]),$row('redaction.'.$p,[rule('path','/context/customer_id')]),$row('redaction.'.$p.'.'.$a,[rule('path','/message')])];
$repo=new RedactionPolicies($db);$other=new IngestEvent('other','info','untouched',$ctx,'2026-01-01T00:00:00Z');
[$one,$two]=$repo->apply([$event,$other],$p,['app'=>$a,'other'=>$b]);
check($one->message===$m&&$two->message==='untouched'&&$two->context->customer_id===$m&&$two->context->nested[0]->Email===$m,'scope policies combine without source bleed');
check(count($db->queries)===2&&str_contains($db->queries[0],'pg_advisory_xact_lock_shared'),'one bounded policy read protected by shared transaction lock');
$preview=$repo->apply([$event],$p,['app'=>$a],draft:['project'=>$p,'source'=>$a,'rules'=>[]])[0];
check($preview->message!==$m&&$preview->context->customer_id===$m,'draft replaces only selected scope, inherited rules remain');
$db->rows=[['key'=>'redaction.system','value'=>'{"revision":1,"rules":{}}']];
try{$repo->apply([$event],$p,['app'=>$a]);$badPolicy=false;}catch(\Cajeer\Logs\Database\DatabaseException){$badPolicy=true;}
check($badPolicy,'corrupted stored policy fails closed');
$db->active=false;try{$repo->apply([$event],$p,['app'=>$a]);$noTx=false;}catch(LogicException){$noTx=true;}check($noTx,'policy writes require transaction');
foreach(['/system/redaction','/projects/'.$p.'/redaction','/projects/'.$p.'/sources/'.$a.'/redaction'] as $path)check($app->handle(new Request('GET','/api/v1'.$path))->status===401,'policy route requires authentication');
echo "Redaction & Data Privacy: $n checks passed\n";
