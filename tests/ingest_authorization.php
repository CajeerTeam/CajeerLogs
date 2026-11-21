<?php
declare(strict_types=1);
use Cajeer\Logs\Security\{IngestAuthorization as A,IngestPrincipal as P,Identity};
use Cajeer\Logs\Http\{Request,HttpException,ErrorHandler,JsonResponse};
use Cajeer\Logs\Database\Repository\TokenRepository;
use Cajeer\Logs\Queue\PostgresQueueDriver;
use Cajeer\Logs\Observability\IngestMetrics;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function denied(callable $f,int $status,string $error):bool{try{$f();}catch(HttpException $e){return $e->status===$status&&$e->error===$error;}return false;}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$t='33333333-3333-4333-8333-333333333333';$account='44444444-4444-4444-8444-444444444444';$secret='cl_'.str_repeat('a',64);
$row=['id'=>$t,'name'=>'writer','project_id'=>$p,'source_id'=>$s,'scopes'=>['logs:write','logs:read'],'service_account_id'=>$account,'requests_per_minute'=>3,'usage_due'=>true];
$principal=fn(array $changes=[])=>P::fromIdentity(['kind'=>'token','id'=>$t,'token'=>array_replace($row,$changes)]);
$x=$principal();check($x->id===$t&&$x->projectId===$p&&$x->sourceId===$s&&$x->scopes===['logs:read','logs:write'],'canonical checked principal');
check(!str_contains(json_encode($x),$secret)&&!isset($x->secret)&&!isset($x->token_hash),'no credential secret in principal');
foreach([['project_id'=>null],['scopes'=>['logs:read']],['scopes'=>['system:read']],['scopes'=>['logs:write','logs:write']],['scopes'=>(object)['0'=>'logs:write']],['source_id'=>'bad'],['service_account_id'=>'bad'],['requests_per_minute'=>0],['id'=>$p]] as $bad)check(denied(fn()=>$principal($bad),403,'forbidden'),'reject malformed/unauthorized principal '.array_key_first($bad));
check(denied(fn()=>P::fromIdentity(['kind'=>'session']),403,'forbidden'),'session cannot become ingest principal');
$x->assertSource($s);check(denied(fn()=>$x->assertSource($p),403,'forbidden'),'source boundary');
$principal(['source_id'=>null])->assertSource($p);check(true,'project-wide credential can use a resolved source');
$x->assertUnchanged($principal(['scopes'=>['logs:read','logs:write']]));check(true,'scope order not policy change');
foreach([['source_id'=>null],['project_id'=>$s],['service_account_id'=>null],['scopes'=>['logs:write']]] as $bad)check(denied(fn()=>$x->assertUnchanged($principal($bad)),403,'forbidden'),'scope change denied '.array_key_first($bad));
check(denied(fn()=>$x->assertUnchanged($principal(['requests_per_minute'=>1])),503,'authorization_changed'),'concurrent quota change requires retry');
try{$x->assertUnchanged($principal(['requests_per_minute'=>null]));}catch(HttpException $e){$r=ErrorHandler::response($e,'test');check($r->status===503&&$r->header('Retry-After')==='1'&&json_decode($r->body,true)['error']==='authorization_changed','safe retry response');}
$r=fn(string $ip='192.0.2.1',string $method='POST',string $path='/api/v1/ingest')=>new Request($method,$path,['Authorization'=>'Bearer '.$secret,'X-Forwarded-For'=>'198.51.100.99','Forwarded'=>'for=203.0.113.9','X-Real-IP'=>'203.0.113.99'],[],'',$ip);
check(A::peerKey($r())==='192.0.2.1'&&A::peerKey($r('::ffff:192.0.2.1'))==='192.0.2.1','ignore proxy headers and collapse mapped addresses');
check(A::peerKey($r('2001:0db8:0:0:0:0:0:1'))==='2001:db8::1'&&A::peerKey($r('not-an-ip'))==='unknown','canonical IPv6 and bounded unknown bucket');
check(denied(fn()=>A::credentials(new Request('POST','/',['Cookie'=>'cajeerlogs_session='.str_repeat('b',64)])),403,'forbidden'),'bearer-only ingest');
check(denied(fn()=>A::credentials(new Request('POST','/',['Authorization'=>'Bearer '.$secret,'Cookie'=>'cajeerlogs_session='.str_repeat('b',64)])),401,'unauthorized'),'ambiguous credentials rejected');
check(TokenRepository::decode(['scopes'=>'{"0":"logs:write"}'])['scopes'] instanceof stdClass,'database object cannot become scope list');
// Exercise the actual authorization service against a controlled PDO fixture.
final class AuthPDO extends PDO {
    public bool $active=false;public array $calls=[];public array $hits=[];public mixed $row;public mixed $job=false;
    public function __construct(){}
    public function inTransaction():bool{return $this->active;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new AuthStmt($this,$query);}
}
final class AuthStmt extends PDOStatement {
    private mixed $value=false;
    public function __construct(private AuthPDO $pdo,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->pdo->calls[]=[$this->sql,$params];
        if(str_contains($this->sql,'INSERT INTO auth_rate_limits')){$key=$params['key'];$this->pdo->hits[$key]=($this->pdo->hits[$key]??0)+1;$this->value=['hits'=>$this->pdo->hits[$key],'retry_after'=>17];}
        elseif(str_contains($this->sql,'FROM jobs'))$this->value=$this->pdo->job;
        elseif(str_contains($this->sql,'FROM api_tokens'))$this->value=$this->pdo->row;
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->value;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
$db=new AuthPDO();$db->row=array_replace($row,['scopes'=>'["logs:write","logs:read"]']);$auth=new A($db,2);$credential=A::credentials($r());
$a=$auth->begin($r(),$credential);check(count($db->hits)===3&&array_sum($db->hits)===3,'IP, token and credential buckets charged once');
check(!str_contains(json_encode($db->calls),'UPDATE api_tokens')&&!str_contains(json_encode($db->calls),$secret),'preflight does not write token or bind raw credential');
$db->active=true;$auth->locked($r(),$credential,$a);$auth->locked($r(),$credential,$a,false);
check(array_sum($db->hits)===3&&count(array_filter($db->calls,fn($c)=>str_contains($c[0],'FOR NO KEY UPDATE')))===2,'locked checks never charge again');
check(count(array_filter($db->calls,fn($c)=>str_starts_with($c[0],'UPDATE api_tokens')))===1,'usage update only at locked admission');
$db->row['requests_per_minute']=1;check(denied(fn()=>$auth->locked($r(),$credential,$a,false),503,'authorization_changed'),'service rejects changed credential limit under lock');
$db->row=false;check(denied(fn()=>$auth->locked($r(),$credential,$a,false),401,'unauthorized'),'revoked credential rejected on reauthorization');
$db->row=array_replace($row,['scopes'=>'["logs:write","logs:read"]']);$db->active=false;
$auth->begin($r('192.0.2.1','POST','/api/v1/ingest/async'),$credential);
check(denied(fn()=>$auth->begin($r('192.0.2.2','GET','/api/v1/ingest/jobs/7'),$credential),429,'rate_limited'),'poll cannot bypass token rate across IPs');
check(denied(fn()=>$auth->begin($r('::ffff:192.0.2.1','GET','/api/v1/ingest/jobs/7'),$credential),429,'rate_limited'),'poll cannot bypass IP rate via IPv6 mapping');
$db->calls=[];$q=new PostgresQueueDriver($db);check($q->status('7',$t,$p)===null&&str_contains($db->calls[0][0],'project_id=:project')&&$db->calls[0][1]===['id'=>'7','token'=>$t,'project'=>$p],'job status query binds both owner and project');
check(IngestMetrics::mode('GET','/api/v1/ingest/jobs/7','/api/v1')==='job'&&IngestMetrics::mode('GET','/api/v1/logs','/api/v1')===null,'job polling included in bounded metrics');
$tmp=sys_get_temp_dir().'/cl-auth-'.bin2hex(random_bytes(8));mkdir($tmp,0700);try{$m=new IngestMetrics($tmp);$m->record('job',new JsonResponse(['error'=>'unauthorized'],401),0.01);$m->record('sync',new JsonResponse(['error'=>'authorization_changed'],503),0.01);$out=$m->render();check(str_contains($out,'mode="job",reason="unauthorized"')&&str_contains($out,'mode="sync",reason="authorization_changed"'),'bounded authentication/policy rejection metrics');}finally{foreach(glob($tmp.'/*') as $file)unlink($file);rmdir($tmp);}
$pol=\Cajeer\Logs\Security\RateLimitPolicy::fromConfig(new \Cajeer\Logs\Config\ConfigRepository(['api'=>['rate_limit'=>600,'rate_burst_percent'=>25]]));
check($pol->snapshot()['global']===['per_minute'=>600,'burst'=>150]&&$pol->snapshot()['export']===['per_minute'=>10,'burst'=>2],'policy validates configured burst and distinct operation budgets');
foreach([0,101,true,'invalid'] as $invalid){try{\Cajeer\Logs\Security\RateLimitPolicy::fromConfig(new \Cajeer\Logs\Config\ConfigRepository(['api'=>['rate_limit'=>600,'rate_burst_percent'=>$invalid]]));throw new RuntimeException('invalid accepted');}catch(InvalidArgumentException){check(true,'invalid burst rejected');}}
try{(new \Cajeer\Logs\Security\RateLimiter($db))->enforce('fixed-test',1,60);(new \Cajeer\Logs\Security\RateLimiter($db))->enforce('fixed-test',1,60);throw new RuntimeException('Expected denial');}catch(HttpException $e){check($e->status===429&&$e->headers['Retry-After']==='17'&&$e->headers['Cache-Control']==='no-store','fixed-window Retry-After uses database remainder');}
check(IngestMetrics::identityMode('POST','/api/v1/auth/login','/api/v1')==='login'&&IngestMetrics::identityMode('GET','/api/v1/projects/secret','/api/v1')==='read'&&IngestMetrics::identityMode('POST','/api/v1/ingest','/api/v1')===null,'bounded identity metrics categories');
echo "Ingest Authorization: $n checks passed\n";
