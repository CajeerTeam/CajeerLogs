<?php
declare(strict_types=1);
use Cajeer\Logs\Security\{SignedPayload,IngestSigning,IngestPrincipal};
use Cajeer\Logs\Http\{Request,HttpException};
use Cajeer\Logs\Database\DatabaseException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function rejects(callable $fn,int $code=401):bool{try{$fn();}catch(HttpException $e){return $e->status===$code;}return false;}
function broken(callable $fn):bool{try{$fn();}catch(DatabaseException){return true;}return false;}
final class SigningPDO extends PDO {
    public array $rows=[];public array $calls=[];public bool $active=false;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new SigningStmt($this,$query,array_shift($this->rows));}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$this->calls[]=[$query,[]];return new SigningStmt($this,$query,array_shift($this->rows));}
}
final class SigningStmt extends PDOStatement {
    public function __construct(private SigningPDO $pdo,private string $sql,private mixed $row){}
    public function execute(?array $params=null):bool{$this->pdo->calls[]=[$this->sql,$params];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row;}
    public function fetchColumn(int $column=0):mixed{return $this->row;}
}
$db=new SigningPDO();$master=str_repeat('12',32);$keys=new IngestSigning($db,$master);$signer=new SignedPayload();
$token='11111111-1111-4111-8111-111111111111';$project='22222222-2222-4222-8222-222222222222';$kid='33333333-3333-4333-8333-333333333333';$old='44444444-4444-4444-8444-444444444444';$bearer='cl_'.str_repeat('a',64);$secret='sk_'.str_repeat('b',64);$oldSecret='sk_'.str_repeat('c',64);
$p=IngestPrincipal::fromIdentity(['kind'=>'token','id'=>$token,'token'=>['id'=>$token,'project_id'=>$project,'scopes'=>['logs:write']]]);
$ts='1767225600';$nonce=str_repeat('d',32);$body='{"message":"Привет"}';$key='wire-key';
$r=fn(array $h=[],string $b='',string $path='/api/v1/ingest',string $method='POST',array $query=[])=>new Request($method,$path,$h,$query,$b===''?$body:$b);
$canon=SignedPayload::canonicalV2($r(),$ts,$nonce,$key,$kid);
$expected=implode("\n",['cajeerlogs-ingest-v2',$kid,'POST','/api/v1/ingest',$ts,$nonce,$key,hash('sha256',$body)]);
check($canon===$expected,'exact canonical format and UTF-8 body digest');
$headers=fn(string $id,string $s,string $time="1767225600")=>['X-Log-Key-Id'=>$id,'X-Log-Timestamp'=>$time,'X-Log-Nonce'=>$nonce,'X-Log-Signature'=>$signer->sign(SignedPayload::canonicalV2($r(),$time,$nonce,$key,$id),$s)];
$h=$headers($kid,$secret);
check($signer->verify($r($h),$secret,$key,true,(int)$ts,$kid)===$nonce,'valid v2 signature');
foreach([[$r($h,$body.' '),$key,(int)$ts,$kid],[$r($h,'','/api/v1/ingest/async'),$key,(int)$ts,$kid],[$r($h,'','/api/v1/ingest','GET'),$key,(int)$ts,$kid],[$r($h),'different',(int)$ts,$kid],[$r($h),$key,(int)$ts+301,$kid],[$r($h),$key,(int)$ts-301,$kid],[$r($h),$key,(int)$ts,$old],[$r($h,'','/api/v1/ingest','POST',['x'=>'1']),$key,(int)$ts,$kid]] as $i=>$args)check(rejects(fn()=>$signer->verify($args[0],$secret,$args[1],true,$args[2],$args[3])),'tamper or stale v2 '.$i);
check($signer->verify($r($h),$secret,$key,true,(int)$ts+300,$kid)===$nonce&&$signer->verify($r($h),$secret,$key,true,(int)$ts-300,$kid)===$nonce,'inclusive timestamp window');
check(rejects(fn()=>$signer->verify($r(['X-Log-Key-Id'=>$kid]),$bearer,null,false,(int)$ts)),'key header cannot be silently ignored');
check(rejects(fn()=>$signer->verify($r($h),$bearer,$key,true,(int)$ts,$kid)),'bearer cannot sign v2');
$c=$keys->seal($token,$kid,$secret);$c2=$keys->seal($token,$kid,$secret);
check($c!==$c2&&!str_contains($c,$secret)&&$keys->open($token,$kid,$c)===$secret,'random nonce encryption roundtrip');
check(broken(fn()=>$keys->open($project,$kid,$c))&&broken(fn()=>$keys->open($token,$old,$c)),'ciphertext bound to token and key ID');
check(broken(fn()=>(new IngestSigning($db,str_repeat('34',32)))->open($token,$kid,$c))&&broken(fn()=>$keys->open($token,$kid,'invalid')),'wrong master and damaged ciphertext fail closed');
check(broken(fn()=>(new IngestSigning($db,''))->seal($token,$kid,$secret)),'no encryption key fallback');
$policy=fn(bool $required,mixed $row)=>[['require_ingest_hmac'=>$required],$row,(int)$ts];
$db->rows=$policy(false,false);check($keys->verify($r(),$p,$bearer,null,false)===null,'existing optional v1 behavior');
$db->rows=$policy(true,false);check(rejects(fn()=>$keys->verify($r(),$p,$bearer,null,false)),'mandatory project signing');
$db->rows=[[ 'require_ingest_hmac'=>'t' ],false,(int)$ts];check(rejects(fn()=>$keys->verify($r(),$p,$bearer,null,false)),'string true mandates project HMAC');
$db->rows=[[ 'require_ingest_hmac'=>'f' ],false,(int)$ts];check($keys->verify($r(),$p,$bearer,null,false)===null,'string false retains optional policy');
$db->rows=[[ 'require_ingest_hmac'=>'unexpected' ],false,(int)$ts];check(broken(fn()=>$keys->verify($r(),$p,$bearer,null,false)),'invalid project HMAC policy fails closed');
$db->rows=$policy(false,false);check(rejects(fn()=>$keys->verify($r(),$p,$bearer,null,true)),'global policy cannot be weakened');
$legacy=['current_key_id'=>null];$db->rows=$policy(false,$legacy);check(rejects(fn()=>$keys->verify($r(),$p,$bearer,null,false)),'mandatory credential signing');
$v1=['X-Log-Timestamp'=>$ts,'X-Log-Nonce'=>$nonce,'X-Log-Signature'=>$signer->sign(SignedPayload::canonical($r(),$ts,$nonce,$key),$bearer)];
$db->rows=$policy(false,$legacy);check($keys->verify($r($v1),$p,$bearer,$key,false)===$nonce,'required bearer v1 still works');
$row=['current_key_id'=>$kid,'current_ciphertext'=>$c,'previous_key_id'=>$old,'previous_ciphertext'=>$keys->seal($token,$old,$oldSecret),'previous_active'=>true];
$db->rows=$policy(false,$row);check($keys->verify($r($h),$p,$bearer,$key,false)===$nonce,'current key works');
$db->rows=$policy(false,$row);check($keys->verify($r($headers($old,$oldSecret)),$p,$bearer,$key,false)===$nonce,'previous key during overlap');
$db->rows=$policy(false,array_replace($row,['previous_active'=>false]));check(rejects(fn()=>$keys->verify($r($headers($old,$oldSecret)),$p,$bearer,$key,false)),'expired previous key rejected');
$db->rows=$policy(false,array_replace($row,['previous_active'=>'f']));check(rejects(fn()=>$keys->verify($r($headers($old,$oldSecret)),$p,$bearer,$key,false)),'expired previous key string f rejected');
$db->rows=$policy(false,array_replace($row,['previous_active'=>'t']));check($keys->verify($r($headers($old,$oldSecret)),$p,$bearer,$key,false)===$nonce,'active previous key string t accepted');
$db->rows=$policy(false,$row);check(rejects(fn()=>$keys->verify($r($v1),$p,$bearer,$key,false)),'no fallback to bearer after separate mode');
$db->rows=$policy(false,$row);check(rejects(fn()=>$keys->verify($r(),$p,$bearer,null,false)),'separate mode cannot be unsigned');
$db->active=true;$db->rows=$policy(false,$row);$db->calls=[];$keys->verify($r($h),$p,$bearer,$key,false,true);check(str_contains($db->calls[0][0],'FOR SHARE')&&!str_contains($db->calls[0][0],'FOR UPDATE')&&str_contains($db->calls[2][0],'clock_timestamp'),'shared policy lock and database clock');
check(!str_contains((new \Cajeer\Logs\Log\Redactor())->text('key '.$secret),$secret),'separate signing secret redacted');
$activeToken=['id'=>$token,'scopes'=>'["logs:write"]','revoked_at'=>null,'active'=>true];
$db->rows=[array_replace($activeToken,['active'=>'f'])];check(rejects(fn()=>$keys->configure($project,$token,'separate',0,$old),409),'string f inactive token cannot rotate signing key');
$db->rows=[array_replace($activeToken,['active'=>'t']),['current_key_id'=>$kid,'required'=>'f']];check(rejects(fn()=>$keys->configure($project,$token,'separate',0,$old),409),'string t active token retains revision fence');
$db->rows=[$activeToken,['current_key_id'=>$kid,'required'=>true]];check(rejects(fn()=>$keys->configure($project,$token,'separate',0,$old),409),'stale expected key cannot rotate');
$db->rows=[$activeToken,['current_key_id'=>$kid,'required'=>true]];check(rejects(fn()=>$keys->configure($project,$token,'bearer',0,$kid),409),'separate mode cannot downgrade');
$db->rows=[$activeToken,['current_key_id'=>null,'required'=>false]];check(rejects(fn()=>$keys->configure($project,$token,'separate',1,null),422),'first key requires zero overlap');
// Explicit retirement is guarded by both key IDs and never changes the current key.
$meta=['current_key_id'=>$kid,'previous_key_id'=>$old,'previous_until'=>'2099-01-01T00:00:00Z','required'=>true];
$db->calls=[];$db->rows=[$activeToken,$meta];check(rejects(fn()=>$keys->retirePrevious($project,$token,$kid,$token),409)&&count($db->calls)===2,'stale previous key cannot retire current overlap');
$db->calls=[];$retired=array_replace($meta,['previous_key_id'=>null,'previous_until'=>null]);$db->rows=[$activeToken,$meta,false,$retired];check($keys->retirePrevious($project,$token,$kid,$old)===$retired&&str_contains($db->calls[2][0],'previous_ciphertext=NULL')&&!str_contains($db->calls[2][0],'current_ciphertext'),'retirement clears only previous key material');
$db->active=false;try{$keys->retirePrevious($project,$token,$kid,$old);check(false,'transaction required');}catch(LogicException){check(true,'retirement requires transaction');}
$m=\Cajeer\Logs\Database\MigrationSql::parse(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0013_ingest_signing.sql'));check($m['mode']==='transactional'&&count($m['statements'])===2,'atomic signing migration');
echo "HMAC & Replay Protection: $n checks passed\n";
