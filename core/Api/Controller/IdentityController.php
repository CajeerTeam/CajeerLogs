<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Database\Repository\{UserRepository,ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\SystemReport;
use Cajeer\Logs\Security\{Identity,RateLimiter};
use PDO;
use PDOException;

final class IdentityController
{
    public const ROUTES = [
        'POST /auth/login'=>'login', 'GET /auth/me'=>'me', 'GET /auth/token'=>'token',
        'POST /auth/logout'=>'logout', 'POST /auth/logout-all'=>'logoutAll', 'POST /auth/password'=>'password',
        'GET /users'=>'users', 'POST /users'=>'createUser', 'PATCH /users/{user}'=>'updateUser',
        'GET /projects'=>'projects', 'POST /projects'=>'createProject', 'GET /projects/{project}'=>'project',
        'GET /projects/{project}/members'=>'members', 'PUT /projects/{project}/members/{user}'=>'addMember',
        'DELETE /projects/{project}/members/{user}'=>'removeMember',
        'GET /projects/{project}/sources'=>'sources', 'POST /projects/{project}/sources'=>'createSource',
        'GET /projects/{project}/sources/{source}'=>'source',
        'GET /projects/{project}/tokens'=>'tokens', 'POST /projects/{project}/tokens'=>'createToken',
        'DELETE /projects/{project}/tokens/{token}'=>'revokeToken',
        'GET /system/tokens'=>'systemTokens', 'POST /system/tokens'=>'createSystemToken', 'DELETE /system/tokens/{token}'=>'revokeSystemToken',
        'GET /system/report'=>'report',
    ];
    private Identity $identity;
    private PDO $pdo;
    public function __construct(private readonly Application $app)
    {
        $url=parse_url($app->config()->get('app.url'));
        $secure=$url['scheme']==='https';
        if ($app->config()->get('app.env')==='production' && !$secure) { throw new HttpException(503,'https_required','HTTPS configuration required.'); }
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port']) && !(($secure && $url['port']===443)||(!$secure && $url['port']===80)) ? ':'.$url['port'] : '');
        $this->pdo=$app->container()->get(PDO::class);
        $this->identity=new Identity($this->pdo,$secure,$origin);
    }
    public static function register(Router $router, Application $app, string $prefix): void
    {
        foreach (self::ROUTES as $route=>$action) {
            [$method,$path]=explode(' ',$route,2);
            $router->add($method,$prefix.$path,static function(Request $r) use($app,$action): Response {
                if ($app->config()->get('app.env')==='production' && !$r->secureTransport) { throw new HttpException(403,'https_required','HTTPS is required for identity requests.'); }
                $credential=$action==='login'?null:Identity::credentials($r);
                try { return (new self($app))->dispatch($action,$r,$credential); }
                catch (DatabaseException $e) { throw new HttpException(503,'database_unavailable','Identity storage unavailable.'); }
                catch (PDOException $e) {
                    $code=(string)$e->getCode();
                    if (in_array($code,['23505','23503','23514'],true)) { throw new HttpException(409,'conflict','The operation conflicts with existing data.'); }
                    if (str_starts_with($code,'08') || in_array($code,['42P01','42703','55P03','57014','57P01','40001','40P01'],true)) { throw new HttpException(503,'database_unavailable','Identity storage unavailable.'); }
                    throw $e;
                }
            });
        }
    }
    private function dispatch(string $action, Request $r, ?array $credential): Response
    {
        $rate=new RateLimiter($this->pdo);
        $rate->enforce('identity-ip:'.$r->remoteAddress,$this->app->config()->get('api.rate_limit'),60);
        if ($action==='login') { return $this->login($r); }
        $p=$this->identity->authenticate($r,$credential ?? []);
        $rate->enforce('identity-'.$p['kind'].':'.$p['id'],300,60);
        if ($action==='password') { $rate->enforce('password:'.$p['id'],5,300); }
        // Serialize authorization changes with administrative writes. Recheck after lock.
        if (in_array($r->method,['GET','HEAD'],true)) { return $this->$action($r,$p); }
        $this->pdo->beginTransaction();
        try {
            $this->identity->lock();
            $p=$this->identity->authenticate($r,$credential ?? []);
            $response=$this->$action($r,$p);
            $this->pdo->commit();
            return $response;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }
    private static function invalid(string $message='Invalid request fields.'): never { throw new HttpException(422,'invalid_payload',$message); }
    private static function missing(): never { throw new HttpException(404,'not_found','Resource not found.'); }
    private function body(Request $r,array $allowed): array
    {
        $d=$r->json();
        if (!str_starts_with(ltrim($r->body()),'{') || array_diff(array_keys($d),$allowed)!==[]) { self::invalid(); }
        return $d;
    }
    private static function str(array $d,string $key,int $max=255): string
    {
        $v=$d[$key]??null;
        if (!is_string($v)||$v===''||strlen($v)>$max||str_contains($v,"\0")) { self::invalid('Invalid '.$key.'.'); }
        return $v;
    }
    private static function email(array $d): string
    {
        try { return UserRepository::email(self::str($d,'email')); } catch (DatabaseException) { self::invalid('Invalid email.'); }
    }
    private static function passwordValue(array $d,string $key='password'): string
    {
        $v=self::str($d,$key,1024);
        if (strlen($v)<12) { self::invalid('Password must contain at least 12 bytes.'); }
        return $v;
    }
    private static function role(array $d): string
    {
        $v=self::str($d,'role',32);
        if (!in_array($v,['administrator','operator','viewer'],true)) { self::invalid('Invalid role.'); }
        return $v;
    }
    private static function uuid(string $v): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$v)) { self::invalid('Invalid resource UUID.'); }
        return strtolower($v);
    }
    private function id(Request $r,string $name): string { return self::uuid($r->parameter($name)??''); }
    private function session(array $p): void { if ($p['kind']!=='session') { Identity::forbidden(); } }
    private function allow(array $p,string $permission,?string $project=null,?string $source=null): void { $this->identity->permission($p,$permission,$project,$source); }
    private function audit(Request $r,array $p,string $action,?string $target=null): void
    { (new AuditLogger($this->pdo))->record($action,['request_id'=>$r->requestId],$p['id'],$target); }
    private function sql(string $query,array $params=[]): \PDOStatement { return $this->identity->sql($query,$params); }
    private function one(string $query,array $params): array { $row=$this->sql($query,$params)->fetch(); if (!$row) { self::missing(); } return $row; }
    private function page(Request $r,string $query,array $params=[],string $id='id'): Response
    {
        $limit=filter_var($r->query['limit']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) { self::invalid('limit must be 1..100.'); }
        $cursor=$r->query['cursor']??null;
        if ($cursor!==null) { if (!is_string($cursor)) { self::invalid(); } $params['cursor']=self::uuid($cursor); $query.=" AND $id > :cursor"; }
        $rows=$this->sql($query." ORDER BY $id LIMIT ".($limit+1),$params)->fetchAll();
        $next=null;
        if (count($rows)>$limit) { array_pop($rows); $next=end($rows)['id']; }
        foreach ($rows as &$row) { if (isset($row['scopes']) && is_string($row['scopes'])) { $row['scopes']=json_decode($row['scopes'],true,32,JSON_THROW_ON_ERROR); } }
        unset($row);
        return new JsonResponse(['items'=>$rows,'next_cursor'=>$next]);
    }
    private function login(Request $r): Response
    {
        $d=$this->body($r,['email','password']);
        $result=$this->identity->login($r,self::email($d),self::str($d,'password',1024));
        $cookie=$result['cookie']; unset($result['cookie']);
        return new JsonResponse($result,200,['Set-Cookie'=>$cookie]);
    }
    private function me(Request $r,array $p): Response { $this->session($p); return new JsonResponse(['user'=>$p['user'],'csrf_token'=>$p['csrf']]); }
    private function token(Request $r,array $p): Response { if ($p['kind']!=='token') { Identity::forbidden(); } return new JsonResponse(['token'=>$p['token']]); }
    private function logout(Request $r,array $p): Response
    {
        $this->session($p); $this->sql('DELETE FROM auth_sessions WHERE token_hash=:hash',['hash'=>$p['hash']]); $this->audit($r,$p,'auth.logout');
        return new Response('',204,['Set-Cookie'=>$this->identity->cookie('',true)]);
    }
    private function logoutAll(Request $r,array $p): Response
    {
        $this->session($p); $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$p['id']]); $this->audit($r,$p,'auth.logout_all');
        return new Response('',204,['Set-Cookie'=>$this->identity->cookie('',true)]);
    }
    private function password(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['current_password','password']);
        $old=self::str($d,'current_password',1024); $new=self::passwordValue($d);
        $row=$this->one('SELECT password_hash FROM users WHERE id=:id',['id'=>$p['id']]);
        if (!password_verify($old,$row['password_hash'])) { throw new HttpException(403,'invalid_password','Current password is incorrect.'); }
        $this->sql('UPDATE users SET password_hash=:hash,updated_at=now() WHERE id=:id',['hash'=>password_hash($new,PASSWORD_ARGON2ID),'id'=>$p['id']]);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$p['id']]); $this->audit($r,$p,'auth.password_changed');
        return new Response('',204,['Set-Cookie'=>$this->identity->cookie('',true)]);
    }
    private function users(Request $r,array $p): Response { $this->session($p); $this->allow($p,'users:manage'); return $this->page($r,'SELECT id,email,role,blocked_at,two_factor_enabled,created_at,updated_at FROM users WHERE true'); }
    private function createUser(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $d=$this->body($r,['email','password','role']);
        $id=(new UserRepository($this->pdo))->create(self::email($d),self::passwordValue($d),self::role($d));
        $this->audit($r,$p,'user.created',$id); return new JsonResponse(['id'=>$id],201);
    }
    private function updateUser(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $id=$this->id($r,'user'); $d=$this->body($r,['email','role','blocked','password']);
        if ($d===[]) { self::invalid(); }
        $u=$this->one('SELECT id,email,role,blocked_at,two_factor_enabled FROM users WHERE id=:id FOR UPDATE',['id'=>$id]);
        $role=array_key_exists('role',$d)?self::role($d):$u['role'];
        $blocked=array_key_exists('blocked',$d)?$d['blocked']:$u['blocked_at']!==null;
        if (!is_bool($blocked)) { self::invalid('blocked must be boolean.'); }
        if ($u['role']==='administrator' && $u['blocked_at']===null && !$u['two_factor_enabled'] && ($role!=='administrator'||$blocked) && (int)$this->sql("SELECT count(*) FROM users WHERE role='administrator' AND blocked_at IS NULL AND NOT two_factor_enabled")->fetchColumn()<=1) { throw new HttpException(409,'last_administrator','Cannot disable the last usable administrator.'); }
        $params=['id'=>$id,'email'=>array_key_exists('email',$d)?self::email($d):$u['email'],'role'=>$role,'blocked'=>$blocked?'true':'false'];
        $extra=''; if (array_key_exists('password',$d)) { $params['hash']=password_hash(self::passwordValue($d),PASSWORD_ARGON2ID); $extra=',password_hash=:hash'; }
        $this->sql('UPDATE users SET email=:email,role=:role,blocked_at=CASE WHEN CAST(:blocked AS boolean) THEN COALESCE(blocked_at,now()) ELSE NULL END,updated_at=now()'.$extra.' WHERE id=:id',$params);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$id]);
        $this->audit($r,$p,'user.updated',$id); return new JsonResponse(['id'=>$id]);
    }
    private function projects(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:read');
        return $p['user']['role']==='administrator'?$this->page($r,'SELECT id,name,slug,created_at FROM projects WHERE true'):$this->page($r,'SELECT p.id,p.name,p.slug,p.created_at FROM projects p JOIN project_members m ON m.project_id=p.id WHERE m.user_id=:user',['user'=>$p['id']],'p.id');
    }
    private function nameSlug(array $d): array
    {
        $name=self::str($d,'name'); $slug=self::str($d,'slug',100);
        try { ProjectRepository::validate($name,$slug); } catch (DatabaseException) { self::invalid('Invalid name or slug.'); }
        return [$name,$slug];
    }
    private function createProject(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); [$name,$slug]=$this->nameSlug($this->body($r,['name','slug']));
        $id=(new ProjectRepository($this->pdo))->create($name,$slug); $this->audit($r,$p,'project.created',$id); return new JsonResponse(['id'=>$id],201);
    }
    private function project(Request $r,array $p): Response
    {
        $id=$this->id($r,'project'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$id);
        return new JsonResponse($this->one('SELECT id,name,slug,created_at FROM projects WHERE id=:id',['id'=>$id]));
    }
    private function members(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $id=$this->id($r,'project');
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]);
        return $this->page($r,'SELECT u.id,u.email,u.role,u.blocked_at FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=:project',['project'=>$id],'u.id');
    }
    private function addMember(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $project=$this->id($r,'project'); $user=$this->id($r,'user');
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); $this->one('SELECT id FROM users WHERE id=:id',['id'=>$user]);
        $this->sql('INSERT INTO project_members(project_id,user_id) VALUES (:project,:user) ON CONFLICT DO NOTHING',['project'=>$project,'user'=>$user]);
        $this->audit($r,$p,'project.member_added',$project.':'.$user); return new Response('',204);
    }
    private function removeMember(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $project=$this->id($r,'project'); $user=$this->id($r,'user');
        $this->sql('DELETE FROM project_members WHERE project_id=:project AND user_id=:user',['project'=>$project,'user'=>$user]);
        $this->audit($r,$p,'project.member_removed',$project.':'.$user); return new Response('',204);
    }
    private function sources(Request $r,array $p): Response
    {
        $id=$this->id($r,'project'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$id);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]); return $this->page($r,'SELECT id,project_id,name,slug,created_at FROM sources WHERE project_id=:project',['project'=>$id]);
    }
    private function createSource(Request $r,array $p): Response
    {
        $this->session($p); $project=$this->id($r,'project'); $this->allow($p,'sources:manage',$project); [$name,$slug]=$this->nameSlug($this->body($r,['name','slug']));
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); $id=(new SourceRepository($this->pdo))->create($project,$name,$slug); $this->audit($r,$p,'source.created',$id); return new JsonResponse(['id'=>$id],201);
    }
    private function source(Request $r,array $p): Response
    {
        $project=$this->id($r,'project'); $source=$this->id($r,'source'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$project,$source);
        return new JsonResponse($this->one('SELECT id,project_id,name,slug,created_at FROM sources WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$source]));
    }
    private function tokenList(Request $r,?string $project): Response
    {
        $query='SELECT id,name,scopes,project_id,source_id,created_by,expires_at,revoked_at,last_used_at,created_at FROM api_tokens WHERE ';
        return $project===null?$this->page($r,$query.'project_id IS NULL'):$this->page($r,$query.'project_id=:project',['project'=>$project]);
    }
    private function tokens(Request $r,array $p): Response { $this->session($p); $id=$this->id($r,'project'); $this->allow($p,'tokens:manage',$id); $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]); return $this->tokenList($r,$id); }
    private function systemTokens(Request $r,array $p): Response { $this->session($p); $this->allow($p,'users:manage'); return $this->tokenList($r,null); }
    private function issue(Request $r,array $p,?string $project): Response
    {
        $d=$this->body($r,['name','scopes','source_id','expires_in']); $name=self::str($d,'name'); $scopes=$d['scopes']??null;
        if (trim($name)==='' || !is_array($scopes)||!array_is_list($scopes)||$scopes===[]||count($scopes)>2) { self::invalid('Invalid token scopes or name.'); }
        $allowed=$project===null?['system:read']:['logs:read','logs:write'];
        foreach ($scopes as $s) { if (!is_string($s)||!in_array($s,$allowed,true)) { self::invalid('Invalid token scope.'); } }
        $source=null; if (isset($d['source_id'])) { if ($project===null||!is_string($d['source_id'])) { self::invalid(); } $source=self::uuid($d['source_id']); $this->one('SELECT id FROM sources WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$source]); }
        $ttl=$d['expires_in']??2592000; if (!is_int($ttl)||$ttl<60||$ttl>31536000) { self::invalid('expires_in must be 60..31536000 seconds.'); }
        $result=(new TokenRepository($this->pdo))->create($name,$scopes,$project,$source,new \DateTimeImmutable('+'.$ttl.' seconds'));
        $this->sql('UPDATE api_tokens SET created_by=:actor WHERE id=:id',['actor'=>$p['id'],'id'=>$result['id']]);
        $this->audit($r,$p,'token.created',$result['id']); return new JsonResponse($result,201);
    }
    private function createToken(Request $r,array $p): Response { $this->session($p); $id=$this->id($r,'project'); $this->allow($p,'tokens:manage',$id); $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]); return $this->issue($r,$p,$id); }
    private function createSystemToken(Request $r,array $p): Response { $this->session($p); $this->allow($p,'users:manage'); return $this->issue($r,$p,null); }
    private function revoke(Request $r,array $p,?string $project): Response
    {
        $id=$this->id($r,'token'); $params=['id'=>$id]; $where='project_id IS NULL'; if ($project!==null) { $where='project_id=:project'; $params['project']=$project; }
        $this->one('SELECT id FROM api_tokens WHERE id=:id AND '.$where,$params); (new TokenRepository($this->pdo))->revoke($id);
        $this->audit($r,$p,'token.revoked',$id); return new Response('',204);
    }
    private function revokeToken(Request $r,array $p): Response { $this->session($p); $id=$this->id($r,'project'); $this->allow($p,'tokens:manage',$id); return $this->revoke($r,$p,$id); }
    private function revokeSystemToken(Request $r,array $p): Response { $this->session($p); $this->allow($p,'users:manage'); return $this->revoke($r,$p,null); }
    private function report(Request $r,array $p): Response { $this->allow($p,'system:read'); return (new SystemController($this->app->container()->get(SystemReport::class)))->report(); }
}
