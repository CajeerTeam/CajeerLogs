<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Http\{Request,Response};
if (getenv('CAJEER_TEST_DATABASE')!=='1' || getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable test database.');
$root=dirname(__DIR__); $app=require $root.'/core/bootstrap.php'; $app->boot();
$base=$app->container()->get(PDO::class); $schema='cl_rbac_'.bin2hex(random_bytes(8)); $base->exec('CREATE SCHEMA "'.$schema.'"');
$count=0;
function check(bool $ok,string $label):void { global $count; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$count; echo "PASS $label\n"; }
function req(string $method,string $path,array $headers=[],?array $body=null,array $query=[],int $status=200):Response {
    global $app; if ($body!==null) $headers['Content-Type']='application/json';
    $res=$app->handle(new Request($method,'/api/v1'.$path,$headers,$query,$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR),'127.0.0.1'));
    check($res->status===$status,"$method $path => $status (got {$res->status})"); return $res;
}
function body(Response $r):array {return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function login(string $email):array { $r=req('POST','/auth/login',[],['email'=>$email,'password'=>'rbac-test-password-123']); return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>body($r)['csrf_token']]; }
function role(array $a,string $name,string $scope,array $permissions):array {return body(req('POST','/roles',$a,compact('name','scope','permissions'),[],201));}
function assign(array $a,string $path,?string $role,?string $old=null,int $status=204):void {req('PUT',$path,$a,['role_id'=>$role,'expected_role_id'=>$old],[],$status);}
try {
    $app=new Application($root); $app->boot(); $app->config()->set('database.schema',$schema); $app->config()->set('app.env','test'); $app->config()->set('app.url','http://localhost:8080'); $app->config()->set('security.require_admin_totp',false); $app->config()->set('api.rate_limit',600);
    $pdo=$app->container()->get(PDO::class); $runner=$app->container()->get(MigrationRunner::class); $runner->migrate();
    $stmt=$pdo->prepare('INSERT INTO users(email,password_hash,role) VALUES(:email,:hash,:role) RETURNING id');
    $ids=[]; foreach (['administrator','operator','viewer'] as $baseRole) { $stmt->execute(['email'=>$baseRole.'@rbac.test','hash'=>password_hash('rbac-test-password-123',PASSWORD_ARGON2ID),'role'=>$baseRole]); $ids[$baseRole]=$stmt->fetchColumn(); $stmt->closeCursor(); }
    $a=login('administrator@rbac.test'); $o=login('operator@rbac.test'); $v=login('viewer@rbac.test');
    $p=body(req('POST','/projects',$a,['name'=>'RBAC project','slug'=>'rbac-project'],[],201))['id']; $p2=body(req('POST','/projects',$a,['name'=>'Private','slug'=>'private'],[],201))['id'];
    $pp='/projects/'.$p; $u='/users/'.$ids['operator']; $member=$pp.'/members/'.$ids['operator'];
    req('PUT',$member,$a,null,[],204); req('PUT',$pp.'/members/'.$ids['viewer'],$a,null,[],204);
    $global=role($a,'Support','global',['projects:read','logs:read','logs:write','tokens:read','tokens:create','tokens:rotate','sources:create','audit:read']);
    $project=role($a,'Read only','project',['projects:read','logs:read','logs:export']);
    req('POST','/roles',$a,['name'=>'Escalate','scope'=>'global','permissions'=>['projects:read','roles:manage']],[],422);
    req('POST','/roles',$o,['name'=>'Escalate','scope'=>'global','permissions'=>['projects:read']],[],403);
    req('GET','/roles',$v,null,[],403);
    assign($a,$u.'/role-assignment',$project['id'],null,422);
    assign($a,'/users/'.$ids['administrator'].'/role-assignment',$global['id'],null,422);
    assign($a,$u.'/role-assignment',$global['id']);
    assign($a,$u.'/role-assignment',null,null,409);
    $me=body(req('GET','/auth/me',$o)); check(!in_array('logs:export',$me['user']['permissions'],true),'active session sees new global role');
    $permissions=body(req('GET',$u.'/permissions',$a,null,['project_id'=>$p])); check(in_array('tokens:create',$permissions['permissions'],true),'global restriction grants token creation');
    req('GET','/logs',$o,null,['project_id'=>$p]); req('GET','/logs/export',$o,null,['project_id'=>$p,'format'=>'json'],403);
    req('GET','/logs',$o,null,['project_id'=>$p2],403);
    req('POST',$pp.'/tokens',$o,['name'=>'Forbidden read','scopes'=>['logs:read']],[],403);
    $writer=body(req('POST',$pp.'/tokens',$o,['name'=>'Writer','scopes'=>['logs:write']],[],201));
    $reader=body(req('POST',$pp.'/tokens',$a,['name'=>'Reader','scopes'=>['logs:read']],[],201));
    $readRow=body(req('GET',$pp.'/tokens/'.$reader['id'],$a));
    req('POST',$pp.'/tokens/'.$reader['id'].'/rotate',$o,['revision'=>$readRow['revision'],'expires_in'=>3600,'overlap_seconds'=>0],[],403);
    req('DELETE',$pp.'/tokens/'.$writer['id'],$o,null,[],403);
    req('GET',$pp.'/security/audit',$o);
    assign($a,$member.'/role-assignment',$project['id']);
    $detail=body(req('GET',$pp,$o)); check($detail['effective_permissions']===['projects:read','logs:read'],'global and project intersection');
    $list=body(req('GET','/projects',$o)); check($list['items'][0]['effective_permissions']===$detail['effective_permissions'],'catalog and detail consistent');
    req('GET',$pp.'/tokens',$o,null,[],403); req('GET',$pp.'/security/audit',$o,null,[],403);
    req('POST',$pp.'/sources',$o,['name'=>'Denied','slug'=>'denied'],[],403);
    req('DELETE','/roles/'.$project['id'],$a,['revision'=>$project['revision']],[],409);
    assign($a,$pp.'/members/'.$ids['viewer'].'/role-assignment',$project['id']);
    assign($a,'/users/'.$ids['viewer'].'/role-assignment',$global['id']);
    $viewer=body(req('GET',$pp,$v)); check($viewer['effective_permissions']===['projects:read','logs:read'],'Viewer ceiling prevents escalation');
    req('PATCH','/roles/'.$global['id'],$a,['name'=>'Only projects','permissions'=>['projects:read'],'revision'=>1]);
    req('PATCH','/roles/'.$global['id'],$a,['name'=>'Stale','permissions'=>['projects:read'],'revision'=>1],[],409);
    req('GET','/logs',$o,null,['project_id'=>$p],403);
    req('GET','/logs',$v,null,['project_id'=>$p],403);
    req('GET','/logs',['Authorization'=>'Bearer '.$reader['token']],null,['project_id'=>$p]);
    assign($a,$member.'/role-assignment',null,$project['id']); assign($a,$u.'/role-assignment',null,$global['id']);
    req('GET','/logs',$o,null,['project_id'=>$p]); req('GET',$pp.'/tokens',$o);
    req('DELETE',$member,$a,null,[],204); req('GET','/logs',$o,null,['project_id'=>$p],403);
    $audit=body(req('GET','/system/security/audit',$a,null,['action'=>'role.global_assigned'])); check(count($audit['items'])>=3,'role assignments audited');
    echo "RBAC database: $count checks passed\n";
} finally { $base->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
