<?php
declare(strict_types=1);
use Cajeer\Logs\Database\Repository\TokenRepository as T;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Security\Identity;
use Cajeer\Logs\Http\Request;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function reject(callable $f,string $code='invalid_payload'):bool{try{$f();}catch(HttpException $e){return $e->status===422&&$e->error===$code;}return false;}
$p='11111111-1111-4111-8111-111111111111';$id='22222222-2222-4222-8222-222222222222';
check(T::scopes(['logs:write','logs:read'],$p)===['logs:read','logs:write'],'canonical project scopes');
check(T::scopes(['system:read'],null)===['system:read'],'global system scope');
foreach([[],['logs:read','logs:read'],['system:read'],['*'],['logs:write',1],['a'=>'logs:read'],'logs:read',null] as $bad)check(reject(fn()=>T::scopes($bad,$p)),'invalid project scope '.json_encode($bad));
check(reject(fn()=>T::scopes(['logs:read'],null)),'global log scope rejected');
check(T::narrowedScopes(['logs:read','logs:write'],['logs:read'],$p)===['logs:read'],'rotation narrows scopes');
check(T::narrowedScopes(['logs:write'],null,$p)===['logs:write'],'rotation preserves scopes by default');
check(reject(fn()=>T::narrowedScopes(['logs:read'],['logs:read','logs:write'],$p),'scope_escalation'),'rotation cannot escalate');
check(T::ttl(60)===60&&T::ttl(31536000)===31536000&&T::grace(0)===0&&T::grace(604800)===604800,'bounded expiry and overlap');
foreach([59,31536001,'60',null] as $bad)check(reject(fn()=>T::ttl($bad)),'invalid ttl '.json_encode($bad));
foreach([-1,604801,'0',null] as $bad)check(reject(fn()=>T::grace($bad)),'invalid overlap '.json_encode($bad));
check(T::changes(['name'=>'  Writer ','description'=>'service','requests_per_minute'=>null])===['name'=>'Writer','description'=>'service','requests_per_minute'=>null],'safe metadata');
foreach([[],['scopes'=>['logs:write']],['source_id'=>$id],['project_id'=>$p],['service_account_id'=>$id],['expires_at'=>'2099-01-01'],['name'=>' '],['description'=>str_repeat('a',2001)],['requests_per_minute'=>0],['requests_per_minute'=>'5'],['requests_per_minute'=>1000001]] as $bad)check(reject(fn()=>T::changes($bad)),'invalid patch '.json_encode(array_keys($bad)));
check(T::revision('9223372036854775807')==='9223372036854775807'&&T::revision(1)==='1'&&reject(fn()=>T::revision('9223372036854775808'))&&reject(fn()=>T::revision('01')),'revision bounds');
check(T::decode(['scopes'=>'["logs:read"]','revision'=>1,'requests_per_minute'=>'3'])===['scopes'=>['logs:read'],'revision'=>'1','requests_per_minute'=>3],'public metadata types');
// Controlled PDO statements verify binding, query selection and row-lock/telemetry policy, not SQL execution.
final class TokenStmt extends PDOStatement {
    public function __construct(private TokenPDO $pdo,private string $sql,private mixed $row){}
    public function execute(?array $params=null):bool{$this->pdo->calls[]=[$this->sql,$params];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;}
    public function fetchColumn(int $column=0):mixed{return $this->row;}
}
final class TokenPDO extends PDO {
    public array $calls=[];public array $rows=[];public bool $active=false;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new TokenStmt($this,$query,array_shift($this->rows));}
}
$pdo=new TokenPDO();$repo=new T($pdo);$secret='cl_'.str_repeat('a',64);
check($repo->findActive('bad')===null&&$pdo->calls===[],'malformed secret never reaches SQL');
$row=['id'=>$id,'name'=>'writer','scopes'=>'["logs:write"]','project_id'=>$p,'source_id'=>null,'expires_at'=>null,'requests_per_minute'=>null,'service_account_id'=>null,'usage_due'=>false];
$pdo->rows=[$row];$out=$repo->findActive($secret);
check($out['id']===$id&&$out['scopes']===['logs:write']&&!isset($out['usage_due'])&&count($pdo->calls)===1,'fresh usage does not issue UPDATE');
check($pdo->calls[0][1]===['hash'=>hash('sha256',$secret)]&&!str_contains(json_encode($pdo->calls),$secret),'lookup binds only digest');
check(str_contains($pdo->calls[0][0],'disabled_at IS NULL')&&str_contains($pdo->calls[0][0],'revoked_at IS NULL')&&str_contains($pdo->calls[0][0],'clock_timestamp()'),'eligibility checked by SQL');
$pdo->calls=[];$pdo->rows=[array_replace($row,['usage_due'=>true]),false];$repo->findActive($secret);
check(count($pdo->calls)===2&&str_contains($pdo->calls[1][0],"interval '5 minutes'"),'due timestamp update conditional');
$pdo->active=true;$pdo->calls=[];$pdo->rows=[$row];$repo->findActive($secret);
check(str_starts_with($pdo->calls[0][0],'WITH locked AS MATERIALIZED')&&str_contains($pdo->calls[0][0],'FOR UPDATE'),'transactional lookup locks before eligibility');
$pdo->active=false;$pdo->calls=[];$pdo->rows=[false];check($repo->findActive($secret)===null&&count($pdo->calls)===1,'inactive credential no timestamp update');
try{$repo->rotate($p,$id,'1',3600,0,$id);check(false,'outside transaction');}catch(LogicException){check(true,'rotation requires owned transaction');}
$pdo->active=true;$pdo->calls=[];check(reject(fn()=>$repo->rotate($p,$id,'1',60,60,$id))&&$pdo->calls===[],'overlap cannot outlive successor');
$pdo->rows=[null,['id'=>$id,'revision'=>'2','revoked_at'=>null,'unexpired'=>true,'rotated_to_id'=>null,'scopes'=>['logs:write']]];
try{$repo->rotate($p,$id,'1',3600,0,$id);check(false,'stale revision');}catch(HttpException $e){check($e->status===409&&$e->error==='token_revision_conflict','stale rotation rejected');}
$pdo->rows=[null,['id'=>$id,'revision'=>'1','revoked_at'=>null,'unexpired'=>true,'rotated_to_id'=>'successor','scopes'=>['logs:write']]];
try{$repo->rotate($p,$id,'1',3600,0,$id);check(false,'repeat rotation');}catch(HttpException $e){check($e->status===409&&$e->error==='token_rotation_conflict','repeat rotation rejected');}
$pdo->rows=[null,['id'=>$id,'revision'=>'1','revoked_at'=>null,'unexpired'=>true,'rotated_to_id'=>null,'scopes'=>['logs:read']]];
check(reject(fn()=>$repo->rotate($p,$id,'1',3600,0,$id,['logs:write']),'scope_escalation'),'repository rejects escalation before issue');
echo "API Token Management: $n checks passed\n";
