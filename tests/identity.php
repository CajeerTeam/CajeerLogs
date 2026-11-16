<?php
declare(strict_types=1);

use Cajeer\Logs\Security\{Identity,Rbac,CsrfProtection};
use Cajeer\Logs\Http\{Request,HttpException};
use Cajeer\Logs\Api\Controller\IdentityController;

$app=require dirname(__DIR__).'/core/bootstrap.php';
$app->boot();
$app->config()->set('app.env','test');
$count=0;
function test(bool $ok,string $name):void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$name); } ++$count; echo "PASS $name\n"; }
function denied(callable $fn,int $status):bool { try { $fn(); } catch(HttpException $e) { return $e->status===$status; } return false; }
$token='cl_'.str_repeat('a',64); $cookie='cajeerlogs_session='.str_repeat('b',64);
test(Identity::credentials(new Request('GET','/',['Authorization'=>'Bearer '.$token]))===['kind'=>'token','secret'=>$token],'strict bearer credential');
test(Identity::credentials(new Request('GET','/',['Cookie'=>'other=1; '.$cookie]))['kind']==='session','session cookie parsing');
test(denied(fn()=>Identity::credentials(new Request('GET','/')),401),'missing credentials');
test(denied(fn()=>Identity::credentials(new Request('GET','/',['Authorization'=>'Bearer bad'])),401),'malformed bearer');
test(denied(fn()=>Identity::credentials(new Request('GET','/',['Cookie'=>$cookie.'; '.$cookie])),401),'duplicate session cookies rejected');
test(denied(fn()=>Identity::credentials(new Request('GET','/',['Cookie'=>$cookie,'Authorization'=>'Bearer '.$token])),401),'ambiguous auth rejected');
$csrf=new CsrfProtection(); $value=$csrf->token();
test(strlen($value)===64&&$csrf->verify($value,$value)&&$value!==$csrf->token(),'random CSRF token');
test(!$csrf->verify($value,null)&&!$csrf->verify($value,str_repeat('0',64))&&!$csrf->verify($value,'bad'),'CSRF fails closed');
// Exercise the pure authorization/cookie paths without pretending a database exists.
$noConnection=new class extends PDO { public function __construct() {} };
$identity=new Identity($noConnection,true,'https://logs.example.test');
$project='00000000-0000-0000-0000-000000000001'; $source='00000000-0000-0000-0000-000000000002';
$principal=['kind'=>'token','token'=>['project_id'=>$project,'source_id'=>$source,'scopes'=>['logs:read']]];
$identity->permission($principal,'logs:read',$project,$source);
test(true,'source scoped read permission');
test(denied(fn()=>$identity->permission($principal,'logs:write',$project,$source),403),'scope escalation denied');
test(denied(fn()=>$identity->permission($principal,'logs:read',$project,null),403),'source token cannot read whole project');
test(denied(fn()=>$identity->permission($principal,'logs:read',$source,$source),403),'cross-project token denied');
test(str_contains($identity->cookie(str_repeat('a',64)),'; Secure')&&str_contains($identity->cookie('',true),'Max-Age=0'),'secure cookie and expiry');
test(denied(fn()=>$identity->origin(new Request('POST','/',['Origin'=>'https://evil.example.test'])),403),'cross-origin denied');
$identity->origin(new Request('POST','/',['Origin'=>'https://logs.example.test']));
test(true,'same origin accepted');
test(Identity::publicUser(['id'=>'id','email'=>'a@example.test','password_hash'=>'secret'])===['id'=>'id','email'=>'a@example.test'],'password hash excluded from identity');
$rbac=new Rbac();
test($rbac->roleAllows('administrator','users:manage')&&!$rbac->roleAllows('operator','users:manage'),'admin only user management');
test($rbac->roleAllows('operator','tokens:manage')&&!$rbac->roleAllows('viewer','tokens:manage'),'operator token management');
test($rbac->roleAllows('viewer','logs:read')&&!$rbac->roleAllows('viewer','logs:write'),'viewer read only');
test(!$rbac->roleAllows('unknown','projects:read'),'unknown role denied');
test(Rbac::validate(['logs:read','projects:read'])===['projects:read','logs:read'],'custom role canonical grants');
test(denied(fn()=>Rbac::validate(['projects:read','roles:manage']),422),'custom role cannot grant administration');
test(denied(fn()=>Rbac::validate(['projects:read','logs:export']),422),'export requires read');
test(denied(fn()=>Rbac::validate(['projects:read','tokens:create']),422),'token mutation requires read');
test(denied(fn()=>Rbac::validate(['projects:read','projects:read']),422),'duplicate permission refused');
test(Rbac::restrict(Rbac::permissions('viewer'),'id','global','["projects:read","logs:write","logs:read"]','global')===['projects:read','logs:read'],'viewer ceiling prevents escalation');
test(Rbac::restrict(Rbac::permissions('operator'),'id','project','["projects:read"]','global')===[],'wrong scope fails closed');
test(Rbac::restrict(Rbac::permissions('operator'),'id','global','broken','global')===[],'malformed role fails closed');
test(Rbac::restrict(Rbac::permissions('operator'),null,null,null,'global')===Rbac::permissions('operator'),'legacy user grants preserved');
foreach (IdentityController::ROUTES as $route=>$action) {
    if ($action==='login') { continue; }
    [$method,$path]=explode(' ',$route,2);
    $path=preg_replace('/\{\w+\}/','00000000-0000-0000-0000-000000000001',$path);
    $r=$app->handle(new Request($method,'/api/v1'.$path));
    test($r->status===401 && $r->header('WWW-Authenticate')!==null && $r->header('Cache-Control')==='no-store','unauthenticated '.$route);
}
$app->config()->set('app.env','production');
test($app->handle(new Request('POST','/api/v1/auth/login'))->status===403,'production HTTP rejected before DB');
$app->config()->set('app.env','test');
test($app->handle(new Request('GET','/api/v1/auth/login'))->status===405,'login method restriction');
test($app->handle(new Request('OPTIONS','/api/v1/auth/login'))->status===204,'preflight has no implicit auth bypass');
// Even without a DB, real HTTP dispatch fails closed rather than fabricating a login.
$app->config()->set('database.default','unsupported');
$r=$app->handle(new Request('POST','/api/v1/auth/login',['Content-Type'=>'application/json'],[],'{"email":"admin@example.test","password":"test-only-password"}'));
test($r->status===503&&!str_contains($r->body,'test-only-password'),'database failure safe and explicit');
$policy=new \Cajeer\Logs\Security\IdentityPolicy(['operator'],['logs:export'],120,300);
test($policy->requires('operator',[])&&$policy->requires('viewer',['logs:export'])&&!$policy->requires('viewer',['logs:read']),'2FA role and permission policy');
try { new \Cajeer\Logs\Security\IdentityPolicy(['invalid']); test(false,'invalid policy'); } catch (InvalidArgumentException) { test(true,'invalid policy rejected'); }
$legacyKey=bin2hex(random_bytes(32));$active=bin2hex(random_bytes(32));$next=bin2hex(random_bytes(32));$secret=random_bytes(20);$uid='user-id';
$legacy=new \Cajeer\Logs\Security\TwoFactorManager($noConnection,$legacyKey);$first=new \Cajeer\Logs\Security\TwoFactorManager($noConnection,$legacyKey,false,$active);$second=new \Cajeer\Logs\Security\TwoFactorManager($noConnection,$legacyKey,false,$next,$active);
$cipher=$first->seal($uid,$secret);test(str_starts_with($cipher,'v2:')&&hash_equals($secret,$first->open($uid,$cipher)),'versioned TOTP secret roundtrip');
test(hash_equals($secret,$first->open($uid,$legacy->seal($uid,$secret))),'legacy key readable during rotation');
test(hash_equals($secret,$second->open($uid,$cipher))&&hash_equals($secret,$second->open($uid,$second->seal($uid,$secret))),'previous and active key both readable');
foreach ([fn()=>$first->open('other',$cipher),fn()=>$legacy->open($uid,$cipher),fn()=>$first->open($uid,substr($cipher,0,-5).'AAAAA')] as $f) {try{$f();test(false,'key protection');}catch(\Cajeer\Logs\Database\DatabaseException){test(true,'wrong user, version or tampering rejected');}}
$dlq=\Cajeer\Logs\Queue\DeadLetterManager::class;
test($dlq::id('9223372036854775807')==='9223372036854775807','DLQ bigint ID remains string');
foreach ([0,'0','01','-1','9223372036854775808',[],1.0,'1 OR 1=1'] as $bad) test(denied(fn()=>$dlq::id($bad),422),'DLQ invalid ID rejected');
test($dlq::filters(['limit'=>'10','cursor'=>'9','reason'=>'lease_expired','min_attempts'=>'2'])===['limit'=>10,'cursor'=>'9','reason'=>'lease_expired','min_attempts'=>2],'DLQ bounded filter parsing');
foreach ([['limit'=>101],['reason'=>['database_error']],['source_id'=>'bad'],['min_age_seconds'=>-1],['cursor'=>'0'],['unexpected'=>'x']] as $bad) test(denied(fn()=>$dlq::filters($bad),422),'DLQ malformed filters rejected');
$selected=$dlq::selection([['id'=>'100','attempts'=>3],['id'=>'9','attempts'=>1],['id'=>'11','attempts'=>2]]);
test(array_column($selected,'id')===['9','11','100'],'DLQ batch locks bigint IDs in numeric order');
foreach ([[],[['id'=>'1','attempts'=>1],['id'=>'1','attempts'=>2]],[['id'=>'1','attempts'=>'1']],[['id'=>'1','attempts'=>101]],array_fill(0,21,['id'=>'1','attempts'=>1])] as $bad) test(denied(fn()=>$dlq::selection($bad),422),'DLQ rejects invalid or duplicate selections');
test(denied(fn()=>Rbac::validate(['projects:read','queue:retry']),422),'queue mutation requires queue read');
test(Rbac::validate(['projects:read','queue:read','queue:discard'])===['projects:read','queue:read','queue:discard'],'custom queue permission accepted');
test(!in_array('queue:read',Rbac::permissions('viewer'),true)&&in_array('queue:retry',Rbac::permissions('operator'),true),'queue builtin role ceilings');
echo "Identity runtime: $count checks passed\n";
