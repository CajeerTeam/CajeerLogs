<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Database\TransactionManager;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Database\Repository\{UserRepository,ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\SystemReport;
use Cajeer\Logs\Security\{Identity,RateLimiter,TwoFactorManager};
use PDO;
use PDOException;

final class IdentityController
{
    public const ROUTES = [
        'POST /auth/login'=>'login', 'POST /auth/reauthenticate'=>'reauthenticate', 'GET /auth/me'=>'me', 'GET /auth/token'=>'token',
        'POST /auth/2fa/setup'=>'totpSetup', 'POST /auth/2fa/enable'=>'totpEnable', 'POST /auth/2fa/disable'=>'totpDisable',
        'GET /auth/sessions'=>'sessions', 'DELETE /auth/sessions/{session}'=>'revokeSession', 'POST /auth/logout-others'=>'logoutOthers',
        'PATCH /auth/profile'=>'profile', 'POST /auth/2fa/recovery-codes'=>'recoveryCodes',
        'GET /users/{user}/sessions'=>'userSessions', 'DELETE /users/{user}/sessions/{session}'=>'revokeUserSession', 'POST /users/{user}/sessions/revoke-all'=>'revokeUserSessions',
        'POST /auth/logout'=>'logout', 'POST /auth/logout-all'=>'logoutAll', 'POST /auth/password'=>'password',
        'GET /roles'=>'roles', 'POST /roles'=>'createRole', 'PATCH /roles/{role}'=>'updateRole', 'DELETE /roles/{role}'=>'deleteRole',
        'PUT /users/{user}/role-assignment'=>'assignGlobalRole', 'PUT /projects/{project}/members/{user}/role-assignment'=>'assignProjectRole',
        'GET /users'=>'users', 'POST /users'=>'createUser', 'GET /users/{user}'=>'user', 'PATCH /users/{user}'=>'updateUser',
        'GET /users/{user}/permissions'=>'userPermissions', 'GET /users/{user}/projects'=>'userProjects', 'GET /users/{user}/audit'=>'userAudit', 'POST /users/{user}/2fa/reset'=>'resetUserTotp',
        'GET /projects'=>'projects', 'POST /projects'=>'createProject', 'GET /projects/{project}'=>'project',
        'PATCH /projects/{project}'=>'updateProject', 'POST /projects/{project}/archive'=>'archiveProject', 'POST /projects/{project}/restore'=>'restoreProject', 'GET /projects/{project}/stats'=>'projectStats',
        'GET /projects/{project}/members'=>'members', 'PUT /projects/{project}/members/{user}'=>'addMember',
        'DELETE /projects/{project}/members/{user}'=>'removeMember',
        'GET /projects/{project}/saved-views'=>'savedViews', 'POST /projects/{project}/saved-views'=>'createSavedView',
        'GET /projects/{project}/saved-views/{view}'=>'savedView', 'PATCH /projects/{project}/saved-views/{view}'=>'updateSavedView', 'DELETE /projects/{project}/saved-views/{view}'=>'deleteSavedView',
        'GET /projects/{project}/webhooks'=>'webhooks', 'POST /projects/{project}/webhooks'=>'createWebhook',
        'GET /projects/{project}/webhooks/stats'=>'webhookStats',
        'GET /projects/{project}/webhooks/{webhook}'=>'webhook', 'PATCH /projects/{project}/webhooks/{webhook}'=>'updateWebhook',
        'POST /projects/{project}/webhooks/{webhook}/test'=>'testWebhook',
        'GET /projects/{project}/webhooks/{webhook}/deliveries'=>'webhookDeliveries',
        'GET /projects/{project}/webhooks/{webhook}/attempts'=>'webhookAttempts',
        'POST /projects/{project}/webhooks/{webhook}/deliveries/{delivery}/retry'=>'retryWebhookDelivery',
        'GET /projects/{project}/exports'=>'exportJobs', 'POST /projects/{project}/exports'=>'createExportJob',
        'GET /projects/{project}/exports/{export}'=>'exportJob',
        'GET /projects/{project}/exports/{export}/download'=>'downloadExportJob',
        'GET /projects/{project}/sources'=>'sources', 'POST /projects/{project}/sources'=>'createSource',
        'GET /projects/{project}/sources/{source}'=>'source',
        'PATCH /projects/{project}/sources/{source}'=>'updateSource', 'POST /projects/{project}/sources/{source}/disable'=>'disableSource',
        'POST /projects/{project}/sources/{source}/enable'=>'enableSource', 'POST /projects/{project}/sources/{source}/archive'=>'archiveSource',
        'POST /projects/{project}/sources/{source}/restore'=>'restoreSource', 'GET /projects/{project}/sources/{source}/stats'=>'sourceStats',
        'GET /system/redaction'=>'redactionSystemGet', 'PUT /system/redaction'=>'redactionSystemSet', 'POST /system/redaction/preview'=>'redactionSystemPreview',
        'GET /projects/{project}/redaction'=>'redactionProjectGet', 'PUT /projects/{project}/redaction'=>'redactionProjectSet', 'POST /projects/{project}/redaction/preview'=>'redactionProjectPreview',
        'GET /projects/{project}/sources/{source}/redaction'=>'redactionSourceGet', 'PUT /projects/{project}/sources/{source}/redaction'=>'redactionSourceSet', 'POST /projects/{project}/sources/{source}/redaction/preview'=>'redactionSourcePreview',
        'GET /projects/{project}/signing'=>'projectSigning', 'PUT /projects/{project}/signing'=>'setProjectSigning',
        'GET /projects/{project}/tokens/{token}/signing'=>'tokenSigning', 'POST /projects/{project}/tokens/{token}/signing'=>'configureSigning',
        'GET /projects/{project}/tokens'=>'tokens', 'GET /projects/{project}/tokens/{token}'=>'tokenDetail',
        'DELETE /projects/{project}/tokens/{token}/signing/previous'=>'retireSigningKey',
        'GET /projects/{project}/security/audit'=>'projectSecurityAudit', 'GET /system/security/audit'=>'systemSecurityAudit', 'POST /projects/{project}/tokens'=>'createToken',
        'DELETE /projects/{project}/tokens/{token}'=>'revokeToken',
        'PATCH /projects/{project}/tokens/{token}'=>'updateToken', 'POST /projects/{project}/tokens/{token}/rotate'=>'rotateToken',
        'POST /projects/{project}/tokens/revoke-all'=>'revokeProjectTokens',
        'GET /projects/{project}/service-accounts'=>'serviceAccounts', 'GET /projects/{project}/service-accounts/{account}'=>'serviceAccountDetail', 'POST /projects/{project}/service-accounts'=>'createServiceAccount',
        'POST /projects/{project}/service-accounts/{account}/disable'=>'disableServiceAccount',
        'GET /system/tokens'=>'systemTokens', 'GET /system/tokens/{token}'=>'systemTokenDetail', 'POST /system/tokens'=>'createSystemToken', 'DELETE /system/tokens/{token}'=>'revokeSystemToken',
        'PATCH /system/tokens/{token}'=>'updateSystemToken', 'POST /system/tokens/{token}/rotate'=>'rotateSystemToken',
        'POST /system/tokens/revoke-all'=>'revokeAllSystemTokens',
        'GET /projects/{project}/queue/dead'=>'deadLetters', 'GET /projects/{project}/queue/jobs/{job}'=>'queueJob',
        'POST /projects/{project}/queue/dead/retry'=>'retryDeadLetters', 'POST /projects/{project}/queue/dead/discard'=>'discardDeadLetters',
        'GET /system/queue/dispatch'=>'queueDispatchStatus',
        'POST /system/queue/dispatch/pause'=>'pauseQueueDispatch',
        'POST /system/queue/dispatch/resume'=>'resumeQueueDispatch',
        'GET /system/report'=>'report',
    ];
    private Identity $identity;
    private PDO $pdo;
    private \Cajeer\Logs\Database\MigrationGuard $schemaGuard;
    public function __construct(private readonly Application $app)
    {
        $url=parse_url($app->config()->get('app.url'));
        $secure=$url['scheme']==='https';
        if ($app->config()->get('app.env')==='production' && !$secure) { throw new HttpException(503,'https_required','HTTPS configuration required.'); }
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port']) && !(($secure && $url['port']===443)||(!$secure && $url['port']===80)) ? ':'.$url['port'] : '');
        $this->pdo=$app->container()->get(PDO::class);
        $this->schemaGuard=$app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
        $this->identity=Identity::fromConfig($this->pdo,$secure,$origin,$app->config());
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
                    if (\Cajeer\Logs\Database\DatabaseFailure::unavailable($e) || str_starts_with($code,'08') || in_array($code,['42P01','42703','55P03','57014','57P01','40001','40P01'],true)) { throw new HttpException(503,'database_unavailable','Identity storage unavailable.'); }
                    throw $e;
                }
            });
        }
    }
    private function dispatch(string $action, Request $r, ?array $credential): Response
    {
        $rate=new RateLimiter($this->pdo);
        $policy=\Cajeer\Logs\Security\RateLimitPolicy::fromConfig($this->app->config());
        $policy->peer($rate,$r);
        $rate->enforce('identity-ip:'.\Cajeer\Logs\Security\IngestAuthorization::peerKey($r),$this->app->config()->get('api.rate_limit'),60);
        if ($action==='login') { return $this->login($r); }
        $p=$this->identity->authenticate($r,$credential ?? [],in_array($action,['me','logout','logoutAll','totpSetup','totpEnable'],true));
        $rate->enforce('identity-'.$p['kind'].':'.$p['id'],300,60);
        $operation=in_array($action,['projectStats','sourceStats'],true)?'analytics':(in_array($action,['projectSecurityAudit','systemSecurityAudit'],true)&&($r->query['format']??null)==='ndjson'?'export':(in_array($r->method,['GET','HEAD'],true)||in_array($action,['logout','logoutAll'],true)?null:'admin_write'));
        $policy->principal($rate,$p['kind'],$p['id'],$operation);
        if (in_array($action,['reauthenticate','password','totpSetup','totpEnable','totpDisable','recoveryCodes','resetUserTotp'],true)) { $rate->enforce('password:'.$p['id'],5,300); }
        // Serialize authorization changes with administrative writes. Recheck after lock.
        if (in_array($r->method,['GET','HEAD'],true)) {
            try { return $this->$action($r,$p); } catch (HttpException $e) { $this->auditDenied($r,$p,$action,$e); throw $e; }
        }
        try { return (new TransactionManager($this->pdo))->run(function () use ($r,$credential,$action) {
            $this->identity->lock();
            $p=$this->identity->authenticate($r,$credential ?? [],in_array($action,['me','logout','logoutAll','totpSetup','totpEnable'],true),false);
            // Explicit password+factor operations already perform their own confirmation.
            if (in_array($action,['retryDeadLetters','discardDeadLetters','pauseQueueDispatch','resumeQueueDispatch','createRole','updateRole','deleteRole','assignGlobalRole','assignProjectRole','createUser','updateUser','addMember','removeMember','createToken','createSystemToken','rotateToken','rotateSystemToken','configureSigning','retireSigningKey','setProjectSigning','revokeToken','revokeSystemToken','revokeProjectTokens','revokeAllSystemTokens','disableServiceAccount','createServiceAccount','updateToken','updateSystemToken','redactionSystemSet','redactionProjectSet','redactionSourceSet','createProject','updateProject','archiveProject','restoreProject','createSource','updateSource','disableSource','enableSource','archiveSource','restoreSource','createWebhook','updateWebhook','testWebhook','retryWebhookDelivery','createExportJob'],true)) $this->identity->requireFresh($p);
            $response=$this->$action($r,$p);
            return $response;
        }, maxAttempts: 3); } catch (HttpException $e) {
            if ($action==='reauthenticate' && $e->status===403) $this->audit($r,$p,'auth.reauthentication_failed',$p['id']);
            else $this->auditDenied($r,$p,$action,$e);
            throw $e;
        }
    }
    private function auditDenied(Request $r,array $p,string $action,HttpException $e): void
    {
        // A denied cross-project request must not disclose the caller to that project's operators.
        if ($e->status===403) (new AuditLogger($this->pdo))->record('security.access_denied',['request_id'=>$r->requestId,'operation'=>$action],$p['id'],null,'denied',$p['kind']==='token'?'token':'user');
    }
    private static function invalid(string $message='Invalid request fields.'): never { throw new HttpException(422,'invalid_payload',$message); }
    private static function missing(): never { throw new HttpException(404,'not_found','Resource not found.'); }
    private function body(Request $r,array $allowed): array
    {
        $d=$r->json();
        if (!str_starts_with(ltrim($r->body()),'{') || array_diff(array_keys($d),$allowed)!==[]) { self::invalid(); }
        // Associative decoding otherwise treats {"0":"logs:write"} as a list.
        if (array_key_exists('scopes',$d)) {
            $object=json_decode($r->body(),false,64,JSON_THROW_ON_ERROR);
            if (!is_array($object->scopes)) self::invalid('scopes must be a JSON array.');
        }
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
    private function audit(Request $r,array $p,string $action,?string $target=null,array $context=[]): void
    { (new AuditLogger($this->pdo))->record($action,['request_id'=>$r->requestId]+$context,$p['id'],$target,null,$p['kind']==='token'?'token':'user'); }
    private function sql(string $query,array $params=[]): \PDOStatement { return $this->identity->sql($query,$params); }
    private function one(string $query,array $params): array { $row=$this->sql($query,$params)->fetch(); if (!$row) { self::missing(); } return $row; }
    private function page(Request $r,string $query,array $params=[],string $id='id',bool $projects=false,bool $sources=false,?callable $transform=null): Response
    {
        $limit=filter_var($r->query['limit']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) { self::invalid('limit must be 1..100.'); }
        $cursor=$r->query['cursor']??null;
        if ($cursor!==null) { if (!is_string($cursor)) { self::invalid(); } $params['cursor']=self::uuid($cursor); $query.=" AND $id > :cursor"; }
        $rows=$this->sql($query." ORDER BY $id LIMIT ".($limit+1),$params)->fetchAll();
        $next=null;
        if (count($rows)>$limit) { array_pop($rows); $next=end($rows)['id']; }
        foreach ($rows as &$row) { if ($projects) $row=ProjectRepository::decode($row); if ($sources) $row=SourceRepository::decode($row); if (isset($row['scopes']) && is_string($row['scopes'])) { $row=TokenRepository::decode($row); } }
        unset($row);
        if ($transform!==null) $rows=array_map($transform,$rows);
        return new JsonResponse(['items'=>$rows,'next_cursor'=>$next]);
    }
    /** Bulk exports are session-bound, project-scoped and never accept a token credential. */
    private function exportsFor(Request $r,array $p,?string $source=null): \Cajeer\Logs\Log\ExportQueue
    {
        $this->session($p);
        $project=$this->id($r,'project');
        $this->allow($p,'logs:read',$project,$source);
        $this->allow($p,'logs:export',$project,$source);
        return new \Cajeer\Logs\Log\ExportQueue($this->pdo,$this->app->basePath('storage/exports/private'));
    }
    private function createExportJob(Request $r,array $p): Response
    {
        $this->session($p);
        $project=$this->id($r,'project');
        $body=$this->body($r,['format','filters']);
        [$filters,$format]=\Cajeer\Logs\Log\ExportQueue::filterInput($body,$project);
        $queue=$this->exportsFor($r,$p,$filters['source_id']??null);
        $job=$queue->create($project,$p['id'],$filters,$format);
        $this->audit($r,$p,'logs.export_requested',$job['id'],['project_id'=>$project,'format'=>$format]);
        return new JsonResponse(['job'=>$job],202,['Cache-Control'=>'no-store']);
    }
    private function exportJobs(Request $r,array $p): Response
    {
        $project=$this->id($r,'project');
        $queue=$this->exportsFor($r,$p);
        if (array_diff(array_keys($r->query),['limit'])!==[]) self::invalid('Unknown query parameter.');
        $limit=filter_var($r->query['limit']??30,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) self::invalid('Invalid export list limit.');
        return new JsonResponse(['items'=>$queue->list($project,$p['id'],$limit)]);
    }
    private function exportJob(Request $r,array $p): Response
    {
        $project=$this->id($r,'project');
        $queue=$this->exportsFor($r,$p);
        $job=$queue->get($project,$p['id'],$this->id($r,'export'));
        $this->exportsFor($r,$p,$job['source_id']);
        return new JsonResponse(['job'=>$job]);
    }
    private function downloadExportJob(Request $r,array $p): Response
    {
        $project=$this->id($r,'project');
        $queue=$this->exportsFor($r,$p);
        $id=$this->id($r,'export');
        $job=$queue->get($project,$p['id'],$id);
        $this->exportsFor($r,$p,$job['source_id']);
        $response=$queue->download($project,$p['id'],$id);
        $this->audit($r,$p,'logs.export_downloaded',$id,['project_id'=>$project,'format'=>$job['format'],'checksum'=>$job['checksum']]);
        return $response;
    }
    private function login(Request $r): Response
    {
        $d=$this->body($r,['email','password','otp']);
        $otp=isset($d['otp'])?self::str($d,'otp',32):null;
        $result=$this->identity->login($r,self::email($d),self::str($d,'password',1024),$otp);
        $cookie=$result['cookie']; unset($result['cookie']);
        return new JsonResponse($result,200,['Set-Cookie'=>$cookie]);
    }
    private function me(Request $r,array $p): Response { $this->session($p); return new JsonResponse(['user'=>$p['user']+['permissions'=>$this->identity->effectivePermissions($p)],'csrf_token'=>$p['csrf'],'enrollment_required'=>$this->identity->enrollmentRequired($p['user']),'security'=>$this->identity->securityState($p['user'],$this->freshUntil($p))]); }
    private function totp(): TwoFactorManager { return $this->identity->totp(); }
    private function freshUntil(array $p): ?string {
        $seconds=$this->identity->policy()->fresh;
        return $seconds>0 && !empty($p['reauthenticated_at']) ? gmdate('Y-m-d\TH:i:s\Z',strtotime($p['reauthenticated_at'])+$seconds):null;
    }
    private function reauthenticate(Request $r,array $p): Response {
        $this->session($p); $d=$this->body($r,['password','otp']);
        $this->confirmPassword($p,$d);
        if ($p['user']['two_factor_enabled'] && !$this->totp()->verify($p['id'],isset($d['otp'])?self::str($d,'otp',32):null)) throw new HttpException(403,'invalid_otp','Invalid second factor.');
        $row=$this->one('UPDATE auth_sessions SET reauthenticated_at=clock_timestamp() WHERE token_hash=:hash RETURNING reauthenticated_at',['hash'=>$p['hash']]);
        $this->audit($r,$p,'auth.reauthenticated',$p['id']);
        return new JsonResponse(['security'=>$this->identity->securityState($p['user'],$this->freshUntil($row))]);
    }
    private function confirmPassword(array $p,array $d): void
    {
        $row=$this->one('SELECT password_hash FROM users WHERE id=:id',['id'=>$p['id']]);
        if (!password_verify(self::str($d,'password',1024),$row['password_hash'])) { throw new HttpException(403,'invalid_password','Current password is incorrect.'); }
    }
    private function totpSetup(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['password']); $this->confirmPassword($p,$d);
        if ($p['user']['two_factor_enabled']) { throw new HttpException(409,'conflict','Two-factor authentication already enabled.'); }
        $data=$this->totp()->setup($p['id'],$p['user']['email'],$p['hash']); $this->audit($r,$p,'auth.totp_setup');
        return new JsonResponse($data);
    }
    private function totpEnable(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['otp']);
        if ($p['user']['two_factor_enabled']) { throw new HttpException(409,'conflict','Two-factor authentication already enabled.'); }
        $codes=$this->totp()->enable($p['id'],$p['hash'],self::str($d,'otp',6));
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$p['id']]);
        $user=$this->one('SELECT id,email,role,blocked_at,two_factor_enabled,created_at,updated_at,display_name,last_login_at FROM users WHERE id=:id',['id'=>$p['id']]);
        $result=$this->identity->issueSession($user,$r); $cookie=$result['cookie']; unset($result['cookie']);
        $this->audit($r,$p,'auth.totp_enabled');
        return new JsonResponse($result+['recovery_codes'=>$codes],200,['Set-Cookie'=>$cookie]);
    }
    private function totpDisable(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['password','otp']); $this->confirmPassword($p,$d);
        if ($this->identity->totpRequired($p['user'])) { throw new HttpException(403,'forbidden','Two-factor authentication is required by security policy.'); }
        if (!$p['user']['two_factor_enabled'] || !$this->totp()->verify($p['id'],self::str($d,'otp',32))) { throw new HttpException(403,'invalid_otp','Invalid second factor.'); }
        $this->totp()->disable($p['id']); $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$p['id']]); $this->audit($r,$p,'auth.totp_disabled');
        return new Response('',204,['Set-Cookie'=>$this->identity->cookie('',true)]);
    }
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
        $this->session($p); $d=$this->body($r,['current_password','password','otp']);
        $old=self::str($d,'current_password',1024); $new=self::passwordValue($d);
        $row=$this->one('SELECT password_hash FROM users WHERE id=:id',['id'=>$p['id']]);
        if (!password_verify($old,$row['password_hash'])) { throw new HttpException(403,'invalid_password','Current password is incorrect.'); }
        if ($p['user']['two_factor_enabled'] && !$this->totp()->verify($p['id'],isset($d['otp'])?self::str($d,'otp',32):null)) throw new HttpException(403,'invalid_otp','Invalid second factor.');
        $this->sql('UPDATE users SET password_hash=:hash,updated_at=now() WHERE id=:id',['hash'=>password_hash($new,PASSWORD_ARGON2ID),'id'=>$p['id']]);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$p['id']]); $this->audit($r,$p,'auth.password_changed');
        return new Response('',204,['Set-Cookie'=>$this->identity->cookie('',true)]);
    }
    private function sessionList(Request $r,array $p,string $user): Response
    {
         $idle=$this->identity->policy()->idle; $expiry=$idle>0?"LEAST(expires_at,last_seen_at+interval '$idle seconds')":'expires_at';
        return $this->page($r,"SELECT id,created_at,last_seen_at,expires_at,$expiry AS effective_expires_at,user_agent,remote_address,(id=CAST(:current AS uuid)) AS current FROM auth_sessions WHERE user_id=:user AND $expiry>clock_timestamp()", ['user'=>$user,'current'=>$p['session_id']]);
    }
    private function sessions(Request $r,array $p): Response
    { $this->session($p); return $this->sessionList($r,$p,$p['id']); }
    private function userSessions(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $user=$this->id($r,'user');
        $this->one('SELECT id FROM users WHERE id=:id',['id'=>$user]);
        return $this->sessionList($r,$p,$user);
    }
    private function revokeOne(Request $r,array $p,string $user): Response
    {
        $id=$this->id($r,'session');
        $deleted=$this->sql('DELETE FROM auth_sessions WHERE id=:id AND user_id=:user AND expires_at>now() RETURNING id',['id'=>$id,'user'=>$user])->fetchColumn();
        if ($deleted===false) self::missing();
        $this->audit($r,$p,'auth.session_revoked',$user,['session_id'=>$id]);
        return new Response('',204,$id===$p['session_id']?['Set-Cookie'=>$this->identity->cookie('',true)]:[]);
    }
    private function revokeSession(Request $r,array $p): Response
    { $this->session($p); return $this->revokeOne($r,$p,$p['id']); }
    private function revokeUserSession(Request $r,array $p): Response
    { $this->session($p); $this->allow($p,'users:manage'); return $this->revokeOne($r,$p,$this->id($r,'user')); }
    private function logoutOthers(Request $r,array $p): Response
    {
        $this->session($p);
        $count=$this->sql('DELETE FROM auth_sessions WHERE user_id=:user AND id<>:id',['user'=>$p['id'],'id'=>$p['session_id']])->rowCount();
        $this->audit($r,$p,'auth.logout_others',$p['id'],['sessions_revoked'=>$count]);
        return new Response('',204);
    }
    private function revokeUserSessions(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $user=$this->id($r,'user');
        $this->one('SELECT id FROM users WHERE id=:id',['id'=>$user]);
        $count=$this->sql('DELETE FROM auth_sessions WHERE user_id=:user',['user'=>$user])->rowCount();
        $this->audit($r,$p,'auth.user_sessions_revoked',$user,['sessions_revoked'=>$count]);
        return new Response('',204,$user===$p['id']?['Set-Cookie'=>$this->identity->cookie('',true)]:[]);
    }
    private function profile(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['display_name']); $name=$d['display_name']??null;
        if (!is_string($name)||strlen($name)>480||!preg_match('/^[^\p{C}]{0,120}$/uD',$name)) self::invalid('Display name must contain at most 120 characters and no control characters.');
        $user=$this->one('UPDATE users SET display_name=:name,updated_at=now() WHERE id=:id RETURNING id,email,role,blocked_at,two_factor_enabled,created_at,updated_at,display_name,last_login_at',['name'=>trim($name),'id'=>$p['id']]);
        $this->audit($r,$p,'auth.profile_updated',$p['id']);
        return new JsonResponse(['user'=>$user+['permissions'=>$this->identity->effectivePermissions($p)]]);
    }
    private function recoveryCodes(Request $r,array $p): Response
    {
        $this->session($p); $d=$this->body($r,['password','otp']); $this->confirmPassword($p,$d);
        if (!$p['user']['two_factor_enabled'] || !$this->totp()->verify($p['id'],self::str($d,'otp',32))) throw new HttpException(403,'invalid_otp','Invalid second factor.');
        $codes=$this->totp()->regenerateRecoveryCodes($p['id']);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id AND token_hash<>:hash',['id'=>$p['id'],'hash'=>$p['hash']]);
        $this->audit($r,$p,'auth.recovery_codes_regenerated',$p['id']);
        return new JsonResponse(['recovery_codes'=>$codes]);
    }
    private const USER_FIELDS='id,email,role,blocked_at,two_factor_enabled,created_at,updated_at,display_name,last_login_at,rbac_role_id';
    private function managedUser(Request $r,array $p): array
    {
        $this->session($p); $this->allow($p,'users:manage');
        return $this->one('SELECT '.self::USER_FIELDS.' FROM users WHERE id=:id',['id'=>$this->id($r,'user')]);
    }
    private function users(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage');
        $query='SELECT '.self::USER_FIELDS.' FROM users WHERE true'; $params=[];
        $q=$r->query['q']??null;
        if ($q!==null) {
            if (!is_string($q)||strlen($q)>255||str_contains($q,"\0")) self::invalid('Invalid user search.');
            if ($q!=='') { $query.=' AND (strpos(lower(email),lower(:email_q))>0 OR strpos(lower(display_name),lower(:name_q))>0 OR strpos(CAST(id AS text),lower(:id_q))>0)'; $params=['email_q'=>$q,'name_q'=>$q,'id_q'=>$q]; }
        }
        $role=$r->query['role']??'all'; $status=$r->query['status']??'all'; $two=$r->query['two_factor']??'all';
        if (!in_array($role,['all','administrator','operator','viewer'],true)||!in_array($status,['all','active','blocked'],true)||!in_array($two,['all','enabled','disabled'],true)) self::invalid('Invalid user filter.');
        if ($role!=='all') { $query.=' AND role=:role'; $params['role']=$role; }
        if ($status!=='all') $query.=' AND blocked_at IS '.($status==='active'?'NULL':'NOT NULL');
        if ($two!=='all') $query.=' AND two_factor_enabled IS '.($two==='enabled'?'TRUE':'FALSE');
        $sort=$r->query['sort']??'id';
        if (!in_array($sort,['id','email_asc','email_desc','newest'],true)) self::invalid('Invalid user sort.');
        if ($sort==='id') return $this->page($r,$query,$params);
        // A UUID identifies the anchor; the selected sort and filters determine its position.
        $column=$sort==='newest'?'created_at':'email'; $descending=$sort!=='email_asc';
        $limit=filter_var($r->query['limit']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) self::invalid('limit must be 1..100.');
        if (isset($r->query['cursor'])) {
            $cursor=$r->query['cursor']; if (!is_string($cursor)) self::invalid();
            $anchor=$this->one('SELECT id,'.$column.' FROM ('.$query.') visible WHERE id=:cursor',$params+['cursor'=>self::uuid($cursor)]);
            $cast=$column==='created_at'?'timestamptz':'text';
            $query.=' AND ('.$column.',id)'.($descending?'<':'>').'(CAST(:sort_value AS '.$cast.'),CAST(:sort_id AS uuid))';
            $params+=['sort_value'=>$anchor[$column],'sort_id'=>$anchor['id']];
        }
        $direction=$descending?' DESC':' ASC';
        $rows=$this->sql($query.' ORDER BY '.$column.$direction.',id'.$direction.' LIMIT '.($limit+1),$params)->fetchAll();
        $next=null; if (count($rows)>$limit) { array_pop($rows); $next=end($rows)['id']; }
        return new JsonResponse(['items'=>$rows,'next_cursor'=>$next]);
    }
    private function user(Request $r,array $p): Response
    { return new JsonResponse(['user'=>$this->managedUser($r,$p)]); }
    private function userPermissions(Request $r,array $p): Response
    {
        $u=$this->managedUser($r,$p); $project=null;
        if (isset($r->query['project_id'])) { if (!is_string($r->query['project_id'])) self::invalid(); $project=self::uuid($r->query['project_id']); $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); }
        $permissions=[];
        if ($u['blocked_at']===null) {
            try { $permissions=$this->identity->effectivePermissions(['kind'=>'session','id'=>$u['id']],$project); }
            catch (HttpException $e) { if ($e->status!==403) throw $e; }
        }
        return new JsonResponse(['user_id'=>$u['id'],'project_id'=>$project,'permissions'=>$permissions],200,['Cache-Control'=>'no-store']);
    }
    private function userProjects(Request $r,array $p): Response
    {
        $u=$this->managedUser($r,$p);
        // Explicit memberships and effective access are distinct. Administrators have implicit access.
        $query="SELECT p.id,p.name,p.slug,p.archived_at,m.project_role,m.rbac_role_id,(m.user_id IS NOT NULL) AS member, CASE WHEN u.blocked_at IS NOT NULL THEN NULL WHEN u.role='administrator' THEN 'administrator' WHEN m.user_id IS NULL THEN NULL WHEN u.role='viewer' OR m.project_role='viewer' THEN 'viewer' ELSE u.role END AS effective_role FROM projects p CROSS JOIN users u LEFT JOIN project_members m ON m.project_id=p.id AND m.user_id=u.id WHERE u.id=:user AND (u.role='administrator' OR m.user_id IS NOT NULL)";
        return $this->page($r,$query,['user'=>$u['id']],'p.id');
    }
    private function userAudit(Request $r,array $p): Response
    {
        $u=$this->managedUser($r,$p); $id=$u['id'];
        // Never expose arbitrary audit context (it may contain operational data).
        $query="SELECT a.id,a.actor_id,a.action,a.target,a.created_at,a.context->>'request_id' AS request_id, CASE WHEN a.action IN ('user.updated','role.global_assigned','role.project_assigned') THEN a.context->>'role_before' END AS role_before, CASE WHEN a.action IN ('user.updated','role.global_assigned','role.project_assigned') THEN a.context->>'role_after' END AS role_after, CASE WHEN a.action='user.updated' THEN a.context->>'blocked_before' END AS blocked_before, CASE WHEN a.action='user.updated' THEN a.context->>'blocked_after' END AS blocked_after, CASE WHEN a.action='user.updated' THEN a.context->>'password_reset' END AS password_reset, CASE WHEN a.action='project.member_role_set' THEN a.context->>'project_role' END AS project_role FROM audit_log a WHERE (((a.action LIKE 'user.%' OR a.action IN ('role.global_assigned','role.project_assigned')) AND a.target=:target) OR (a.action LIKE 'auth.%' AND (a.actor_id=:actor OR a.target=:auth_target)) OR (a.action IN ('project.member_added','project.member_role_set','project.member_removed') AND right(a.target,37)=:suffix))";
        $params=['target'=>$id,'actor'=>$id,'auth_target'=>$id,'suffix'=>':'.$id];
        $limit=filter_var($r->query['limit']??25,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) self::invalid('limit must be 1..100.');
        if (isset($r->query['cursor'])) {
            $cursor=$r->query['cursor']; if (!is_string($cursor)) self::invalid();
            $anchor=$this->one('SELECT * FROM ('.$query.') visible WHERE id=:cursor',$params+['cursor'=>self::uuid($cursor)]);
            $query.=' AND (a.created_at,a.id)<(CAST(:anchor_time AS timestamptz),CAST(:anchor_id AS uuid))';
            $params+=['anchor_time'=>$anchor['created_at'],'anchor_id'=>$anchor['id']];
        }
        $rows=$this->sql($query.' ORDER BY a.created_at DESC,a.id DESC LIMIT '.($limit+1),$params)->fetchAll();
        $next=null; if (count($rows)>$limit) { array_pop($rows); $next=end($rows)['id']; }
        return new JsonResponse(['items'=>$rows,'next_cursor'=>$next]);
    }
    private function resetUserTotp(Request $r,array $p): Response
    {
        $u=$this->managedUser($r,$p); $d=$this->body($r,['password','otp']);
        if ($u['id']===$p['id']) self::invalid('Use account security settings to change your own second factor.');
        $this->confirmPassword($p,$d);
        if ($p['user']['two_factor_enabled'] && !$this->totp()->verify($p['id'],self::str($d,'otp',32))) throw new HttpException(403,'invalid_otp','Invalid second factor.');
        $this->totp()->disable($u['id']);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$u['id']]);
        $this->audit($r,$p,'user.totp_reset',$u['id']);
        return new Response('',204);
    }
    private function createUser(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $d=$this->body($r,['email','password','role']);
        $id=(new UserRepository($this->pdo))->create(self::email($d),self::passwordValue($d),self::role($d));
        $this->audit($r,$p,'user.created',$id); return new JsonResponse(['id'=>$id],201);
    }
    private function updateUser(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'users:manage'); $id=$this->id($r,'user'); $d=$this->body($r,['email','role','blocked','password','expected_updated_at']);
        if ($d===[]) { self::invalid(); }
        $u=$this->one('SELECT id,email,role,blocked_at,two_factor_enabled,updated_at FROM users WHERE id=:id FOR UPDATE',['id'=>$id]);
        if (array_key_exists('expected_updated_at',$d) && self::str($d,'expected_updated_at',64)!==$u['updated_at']) throw new HttpException(409,'user_changed','User changed. Reload before editing.');
        $role=array_key_exists('role',$d)?self::role($d):$u['role'];
        $blocked=array_key_exists('blocked',$d)?$d['blocked']:$u['blocked_at']!==null;
        if (!is_bool($blocked)) { self::invalid('blocked must be boolean.'); }
        if ($u['role']==='administrator' && $u['blocked_at']===null && ($role!=='administrator'||$blocked) && (int)$this->sql("SELECT count(*) FROM users u WHERE role='administrator' AND blocked_at IS NULL AND (NOT two_factor_enabled OR EXISTS(SELECT 1 FROM user_totp t WHERE t.user_id=u.id AND t.pending_session IS NULL))")->fetchColumn()<=1) { throw new HttpException(409,'last_administrator','Cannot disable the last usable administrator.'); }
        $params=['id'=>$id,'email'=>array_key_exists('email',$d)?self::email($d):$u['email'],'role'=>$role,'role_admin'=>$role,'blocked'=>$blocked?'true':'false'];
        $extra=''; if (array_key_exists('password',$d)) { $params['hash']=password_hash(self::passwordValue($d),PASSWORD_ARGON2ID); $extra=',password_hash=:hash'; }
        $this->sql('UPDATE users SET email=:email,role=:role,rbac_role_id=CASE WHEN :role_admin=\'administrator\' THEN NULL ELSE rbac_role_id END,blocked_at=CASE WHEN CAST(:blocked AS boolean) THEN COALESCE(blocked_at,now()) ELSE NULL END,updated_at=now()'.$extra.' WHERE id=:id',$params);
        $this->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$id]);
        $this->audit($r,$p,'user.updated',$id,['role_before'=>$u['role'],'role_after'=>$role,'blocked_before'=>$u['blocked_at']!==null,'blocked_after'=>$blocked,'password_reset'=>array_key_exists('password',$d)]); return new JsonResponse(['id'=>$id]);
    }
    private function projects(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:read');
        $fields=implode(',',array_map(static fn($f)=>'p.'.$f,explode(',',ProjectRepository::FIELDS)));
        $admin=$p['user']['role']==='administrator';
        $query='SELECT '.$fields.($admin?",'administrator' AS effective_role":",CASE WHEN m.project_role='viewer' THEN 'viewer' ELSE :role END AS effective_role,m.rbac_role_id AS restriction_id,rr.scope AS restriction_scope,rr.permissions AS restriction_permissions").' FROM projects p'.($admin?' WHERE true':' JOIN project_members m ON m.project_id=p.id LEFT JOIN rbac_roles rr ON rr.id=m.rbac_role_id WHERE m.user_id=:user');
        $params=$admin?[]:['user'=>$p['id'],'role'=>$p['user']['role']];
        $status=$r->query['status']??'all';
        if (!in_array($status,['all','active','archived'],true)) self::invalid('Invalid project status.');
        if ($status!=='all') $query.=' AND p.archived_at IS '.($status==='active'?'NULL':'NOT NULL');
        if (isset($r->query['q'])) {
            $q=$r->query['q']; if (!is_string($q)||strlen($q)>255||str_contains($q,"\0")) self::invalid('Invalid project search.');
            $query.=" AND (strpos(lower(p.name),lower(:q_name))>0 OR strpos(p.slug,lower(:q_slug))>0 OR strpos(p.id::text,lower(:q_id))>0)"; $params['q_name']=$q; $params['q_slug']=$q; $params['q_id']=$q;
        }
        $global=$this->identity->effectivePermissions($p);
        return $this->page($r,$query,$params,'p.id',true,false,static function(array $row) use ($global,$admin): array {
            $grants=array_values(array_intersect($global,\Cajeer\Logs\Security\Rbac::permissions($row['effective_role'])));
            $row['effective_permissions']=$admin?$grants:\Cajeer\Logs\Security\Rbac::restrict($grants,$row['restriction_id'],$row['restriction_scope'],$row['restriction_permissions'],'project');
            unset($row['restriction_id'],$row['restriction_scope'],$row['restriction_permissions']); return $row;
        });
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
        $row=(new ProjectRepository($this->pdo))->find($id); if (!$row) self::missing();
        // Source-scoped tokens cannot access the project-wide endpoint (permission() above).
        if ($p['kind']==='session') { $row['effective_role']=$this->identity->projectRole($p,$id); $row['effective_permissions']=$this->identity->effectivePermissions($p,$id); }
        return new JsonResponse($row);
    }
    private function updateProject(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $id=$this->id($r,'project');
        $d=$this->body($r,['revision','name','description','metadata','retention_days','max_body_bytes','max_batch_events','events_per_minute']);
        $revision=ProjectRepository::revision($d['revision']??null); unset($d['revision']);
        // Preserve the distinction between a JSON object and an array, including {}.
        if (array_key_exists('metadata',$d)) $d['metadata']=json_decode($r->body(),false,64,JSON_THROW_ON_ERROR)->metadata;
        $row=(new ProjectRepository($this->pdo))->update($id,$revision,$d);
        $this->audit($r,$p,'project.updated',$id,['revision'=>$row['revision'],'fields'=>array_keys($d)]); return new JsonResponse($row);
    }
    private function changeProjectState(Request $r,array $p,bool $archived): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $id=$this->id($r,'project');
        $d=$this->body($r,['revision']);
        $row=(new ProjectRepository($this->pdo))->archive($id,ProjectRepository::revision($d['revision']??null),$archived);
        $this->audit($r,$p,$archived?'project.archived':'project.restored',$id,['revision'=>$row['revision']]); return new JsonResponse($row);
    }
    private function archiveProject(Request $r,array $p): Response { return $this->changeProjectState($r,$p,true); }
    private function restoreProject(Request $r,array $p): Response { return $this->changeProjectState($r,$p,false); }
    private function projectStats(Request $r,array $p): Response
    {
        $this->session($p); $id=$this->id($r,'project'); $this->allow($p,'projects:read',$id);
        return new JsonResponse((new ProjectRepository($this->pdo))->statistics($id));
    }
    private function members(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $id=$this->id($r,'project');
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]);
        return $this->page($r,'SELECT u.id,u.email,u.role,u.blocked_at,m.project_role,m.rbac_role_id FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=:project',['project'=>$id],'u.id');
    }
    private function addMember(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $project=$this->id($r,'project'); $user=$this->id($r,'user');
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); $this->one('SELECT id FROM users WHERE id=:id',['id'=>$user]);
        $d=$r->body()===''?[]:$this->body($r,['role']);
        $role=$d['role']??null; if ($role!==null&&!in_array($role,['operator','viewer'],true)) self::invalid('Invalid project role.');
        if (array_key_exists('role',$d)) $this->sql('INSERT INTO project_members(project_id,user_id,project_role) VALUES (:project,:user,:role) ON CONFLICT(project_id,user_id) DO UPDATE SET project_role=EXCLUDED.project_role',['project'=>$project,'user'=>$user,'role'=>$role]);
        else $this->sql('INSERT INTO project_members(project_id,user_id) VALUES (:project,:user) ON CONFLICT DO NOTHING',['project'=>$project,'user'=>$user]);
        $this->audit($r,$p,array_key_exists('role',$d)?'project.member_role_set':'project.member_added',$project.':'.$user,array_key_exists('role',$d)?['project_role'=>$role]:[]); return new Response('',204);
    }
    private function removeMember(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $project=$this->id($r,'project'); $user=$this->id($r,'user');
        $this->sql('DELETE FROM project_members WHERE project_id=:project AND user_id=:user',['project'=>$project,'user'=>$user]);
        $this->audit($r,$p,'project.member_removed',$project.':'.$user); return new Response('',204);
    }
    private const SOURCE_OPTIONS=['description','metadata','source_type','retention_days','max_body_bytes','max_batch_events','events_per_minute'];
    private function sourceData(Request $r,array $extra): array
    {
        $d=$this->body($r,array_merge(self::SOURCE_OPTIONS,$extra));
        if (array_key_exists('metadata',$d)) $d['metadata']=json_decode($r->body(),false,64,JSON_THROW_ON_ERROR)->metadata;
        return $d;
    }
    private function viewScope(Request $r,array $p): array
    {
        $this->session($p); $project=$this->id($r,'project'); $this->allow($p,'logs:read',$project);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]);
        return ['project'=>$project,'owner'=>$p['id']];
    }
    private const VIEW_FIELDS='id,name,filters,layout,revision,created_at,updated_at';
    private function decodeView(array $row,string $project): array
    {
        $row['filters']=json_decode($row['filters'],true,32,JSON_THROW_ON_ERROR);
        $row['layout']=json_decode($row['layout'],false,32,JSON_THROW_ON_ERROR);
        $row['revision']=(int)$row['revision'];
        // Recheck stored filters against the current search contract.
        \Cajeer\Logs\Log\LogQuery::parse(['project_id'=>$project]+$row['filters']);
        return $row;
    }
    private function savedViews(Request $r,array $p): Response
    {
        $scope=$this->viewScope($r,$p);
        $rows=$this->sql('SELECT '.self::VIEW_FIELDS.' FROM saved_log_views WHERE project_id=:project AND owner_id=:owner ORDER BY name,id LIMIT 100',$scope)->fetchAll();
        return new JsonResponse(['items'=>array_map(fn($row)=>$this->decodeView($row,$scope['project']),$rows)],200,['Cache-Control'=>'no-store']);
    }
    private function savedView(Request $r,array $p): Response
    {
        $scope=$this->viewScope($r,$p); $scope['id']=$this->id($r,'view');
        return new JsonResponse($this->decodeView($this->one('SELECT '.self::VIEW_FIELDS.' FROM saved_log_views WHERE project_id=:project AND owner_id=:owner AND id=:id',$scope),$scope['project']),200,['Cache-Control'=>'no-store']);
    }
    private function viewData(Request $r,string $project,bool $update): array
    {
        $d=$this->body($r,$update?['name','filters','layout','revision']:['name','filters','layout']);
        $v=\Cajeer\Logs\Log\SavedView::validate($d,$project);
        return ['name'=>$v['name'],'filters'=>json_encode($v['filters'],JSON_THROW_ON_ERROR),'layout'=>json_encode($v['layout'],JSON_THROW_ON_ERROR)];
    }
    private function createSavedView(Request $r,array $p): Response
    {
        $scope=$this->viewScope($r,$p); $data=$this->viewData($r,$scope['project'],false);
        if ((int)$this->sql('SELECT count(*) FROM saved_log_views WHERE project_id=:project AND owner_id=:owner',$scope)->fetchColumn()>=100) throw new HttpException(409,'conflict','Saved view limit reached (100).');
        $row=$this->one('INSERT INTO saved_log_views(project_id,owner_id,name,filters,layout) VALUES(:project,:owner,:name,CAST(:filters AS jsonb),CAST(:layout AS jsonb)) RETURNING '.self::VIEW_FIELDS,$scope+$data);
        $this->audit($r,$p,'saved_view.created',$row['id'],['project_id'=>$scope['project']]);
        return new JsonResponse($this->decodeView($row,$scope['project']),201,['Cache-Control'=>'no-store']);
    }
    private function updateSavedView(Request $r,array $p): Response
    {
        $scope=$this->viewScope($r,$p); $scope['id']=$this->id($r,'view');
        $old=$this->one('SELECT revision FROM saved_log_views WHERE project_id=:project AND owner_id=:owner AND id=:id',$scope);
        $data=$this->viewData($r,$scope['project'],true); $rev=$r->json()['revision']??null;
        if (!is_int($rev)||$rev<1) self::invalid('Invalid revision.');
        if ((int)$old['revision']!==$rev) throw new HttpException(409,'conflict','Saved view changed. Reload it before updating.');
        $row=$this->one('UPDATE saved_log_views SET name=:name,filters=CAST(:filters AS jsonb),layout=CAST(:layout AS jsonb),revision=revision+1,updated_at=now() WHERE project_id=:project AND owner_id=:owner AND id=:id RETURNING '.self::VIEW_FIELDS,$scope+$data);
        $this->audit($r,$p,'saved_view.updated',$scope['id'],['project_id'=>$scope['project']]);
        return new JsonResponse($this->decodeView($row,$scope['project']),200,['Cache-Control'=>'no-store']);
    }
    private function deleteSavedView(Request $r,array $p): Response
    {
        $scope=$this->viewScope($r,$p); $scope['id']=$this->id($r,'view');
        $this->one('DELETE FROM saved_log_views WHERE project_id=:project AND owner_id=:owner AND id=:id RETURNING id',$scope);
        $this->audit($r,$p,'saved_view.deleted',$scope['id'],['project_id'=>$scope['project']]);
        return new Response('',204,['Cache-Control'=>'no-store']);
    }
    private function sources(Request $r,array $p): Response
    {
        $id=$this->id($r,'project'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$id);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$id]);
        $query='SELECT '.SourceRepository::FIELDS.' FROM sources WHERE project_id=:project'; $params=['project'=>$id];
        $status=$r->query['status']??'all';
        if (!in_array($status,array_merge(['all'],SourceRepository::STATES),true)) self::invalid('Invalid source status.');
        if ($status!=='all') { $query.=' AND status=:status'; $params['status']=$status; }
        $type=$r->query['source_type']??'all';
        if (!in_array($type,array_merge(['all'],SourceRepository::TYPES),true)) self::invalid('Invalid source type.');
        if ($type!=='all') { $query.=' AND source_type=:source_type'; $params['source_type']=$type; }
        if (isset($r->query['q'])) {
            $q=$r->query['q']; if (!is_string($q)||strlen($q)>255||str_contains($q,"\0")) self::invalid('Invalid source search.');
            $query.=' AND (strpos(lower(name),lower(:q_name))>0 OR strpos(slug,lower(:q_slug))>0 OR strpos(id::text,lower(:q_id))>0)'; $params['q_name']=$q; $params['q_slug']=$q; $params['q_id']=$q;
        }
        return $this->page($r,$query,$params,'id',false,true);
    }
    /** Webhook administration uses the established identity, CSRF, fresh-login and audit transaction. */
    private function webhookProject(Request $r,array $p,bool $write=false): string
    {
        $this->session($p);
        $project=$this->id($r,'project');
        $this->allow($p,$write?'services:manage':'services:read',$project);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]);
        return $project;
    }
    private function webhookOutbox(): \Cajeer\Logs\Integration\WebhookOutbox
    { return new \Cajeer\Logs\Integration\WebhookOutbox($this->pdo); }
    private function webhooks(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p);
        return new JsonResponse(['items'=>$this->webhookOutbox()->listProject($project),'supported_events'=>\Cajeer\Logs\Integration\WebhookOutbox::EVENTS],200,['Cache-Control'=>'no-store']);
    }
    private function webhook(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p);
        return new JsonResponse($this->webhookOutbox()->detail($project,$this->id($r,'webhook')),200,['Cache-Control'=>'no-store']);
    }
    private function createWebhook(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p,true);
        $d=$this->body($r,['url','secret_env','events']);
        if (!is_string($d['url']??null)||!is_string($d['secret_env']??null)) self::invalid('URL and environment secret are required.');
        $hook=$this->webhookOutbox();
        $id=$hook->add($project,$d['url'],$d['secret_env'],$d['events']??['logs.ingested']);
        $this->audit($r,$p,'webhook.created',$id,['project_id'=>$project,'events'=>$d['events']??['logs.ingested']]);
        return new JsonResponse($hook->detail($project,$id),201,['Cache-Control'=>'no-store']);
    }
    private function updateWebhook(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p,true);$id=$this->id($r,'webhook');
        $d=$this->body($r,['url','secret_env','events','enabled','revision']);
        if (!is_int($d['revision']??null) || $d['revision']<1) self::invalid('Invalid webhook revision.');
        $revision=$d['revision'];unset($d['revision']);
        $row=$this->webhookOutbox()->update($project,$id,$revision,$d);
        $this->audit($r,$p,'webhook.updated',$id,['project_id'=>$project,'fields'=>array_keys($d),'revision'=>$row['revision']]);
        return new JsonResponse($row,200,['Cache-Control'=>'no-store']);
    }
    private function testWebhook(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p,true);$id=$this->id($r,'webhook');
        if ($r->body()!=='' && $r->body()!=='{}') self::invalid('Test webhook does not accept custom payload.');
        $delivery=$this->webhookOutbox()->test($project,$id);
        $this->audit($r,$p,'webhook.test_queued',$id,['project_id'=>$project,'delivery_id'=>$delivery]);
        return new JsonResponse(['delivery_id'=>$delivery,'status'=>'pending'],202,['Cache-Control'=>'no-store']);
    }
    private function webhookDeliveries(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p);$id=$this->id($r,'webhook');
        $limit=filter_var($r->query['limit']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) self::invalid('Invalid limit.');
        return new JsonResponse(['items'=>$this->webhookOutbox()->deliveries($project,$id,(int)$limit)],200,['Cache-Control'=>'no-store']);
    }
    private function webhookAttempts(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p);$id=$this->id($r,'webhook');
        return new JsonResponse(['items'=>$this->webhookOutbox()->historyProject($project,$id)],200,['Cache-Control'=>'no-store']);
    }
    private function webhookStats(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p);
        return new JsonResponse(['items'=>$this->webhookOutbox()->stats($project)],200,['Cache-Control'=>'no-store']);
    }
    private function retryWebhookDelivery(Request $r,array $p): Response
    {
        $project=$this->webhookProject($r,$p,true);$id=$this->id($r,'webhook');$delivery=$this->id($r,'delivery');
        if ($r->body()!=='' && $r->body()!=='{}') self::invalid('Retry does not accept payload changes.');
        $this->webhookOutbox()->detail($project,$id);
        $changed=$this->webhookOutbox()->retry($delivery,$project,$id);
        if (!$changed) throw new HttpException(409,'conflict','Delivery must be failed and the subscription enabled.');
        $this->audit($r,$p,'webhook.delivery_retried',$id,['project_id'=>$project,'delivery_id'=>$delivery]);
        return new JsonResponse(['delivery_id'=>$delivery,'status'=>'pending'],200,['Cache-Control'=>'no-store']);
    }
    private function createSource(Request $r,array $p): Response
    {
        $this->session($p); $project=$this->id($r,'project'); $this->allow($p,'sources:create',$project);
        $d=$this->sourceData($r,['name','slug']); [$name,$slug]=$this->nameSlug($d); unset($d['name'],$d['slug']);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); $id=(new SourceRepository($this->pdo))->create($project,$name,$slug,$d); $this->audit($r,$p,'source.created',$id,['project_id'=>$project]); (new \Cajeer\Logs\Integration\EventApiDispatcher($this->pdo))->publish($project,'source.created',['source_id'=>$id]); return new JsonResponse(['id'=>$id],201);
    }
    private function source(Request $r,array $p): Response
    {
        $project=$this->id($r,'project'); $source=$this->id($r,'source'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$project,$source);
        $row=(new SourceRepository($this->pdo))->find($project,$source); if (!$row) self::missing(); return new JsonResponse($row);
    }
    private function updateSource(Request $r,array $p): Response
    {
        $this->session($p); $project=$this->id($r,'project'); $source=$this->id($r,'source'); $this->allow($p,'sources:update',$project);
        $d=$this->sourceData($r,['revision','name']); $revision=ProjectRepository::revision($d['revision']??null); unset($d['revision']);
        $row=(new SourceRepository($this->pdo))->update($project,$source,$revision,$d);
        $this->audit($r,$p,'source.updated',$source,['project_id'=>$project,'revision'=>$row['revision'],'fields'=>array_keys($d)]); return new JsonResponse($row);
    }
    private function changeSourceState(Request $r,array $p,string $action): Response
    {
        $this->session($p); $project=$this->id($r,'project'); $source=$this->id($r,'source'); $this->allow($p,'sources:state',$project);
        $d=$this->body($r,['revision']); $row=(new SourceRepository($this->pdo))->transition($project,$source,ProjectRepository::revision($d['revision']??null),$action);
        $this->audit($r,$p,'source.'.$action,$source,['project_id'=>$project,'revision'=>$row['revision'],'status'=>$row['status']]); (new \Cajeer\Logs\Integration\EventApiDispatcher($this->pdo))->publish($project,'source.'.match($action){'disable'=>'disabled','enable'=>'enabled','archive'=>'archived','restore'=>'restored'},['source_id'=>$source,'status'=>$row['status']]); return new JsonResponse($row);
    }
    private function disableSource(Request $r,array $p): Response { return $this->changeSourceState($r,$p,'disable'); }
    private function enableSource(Request $r,array $p): Response { return $this->changeSourceState($r,$p,'enable'); }
    private function archiveSource(Request $r,array $p): Response { return $this->changeSourceState($r,$p,'archive'); }
    private function restoreSource(Request $r,array $p): Response { return $this->changeSourceState($r,$p,'restore'); }
    private function sourceStats(Request $r,array $p): Response
    {
        $project=$this->id($r,'project'); $source=$this->id($r,'source'); $this->allow($p,$p['kind']==='token'?'logs:read':'projects:read',$project,$source);
        $threshold=filter_var($r->query['idle_minutes']??60,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10080]]);
        if ($threshold===false) self::invalid('Invalid idle_minutes parameter.');
        return new JsonResponse((new SourceRepository($this->pdo))->statistics($project,$source,(int)$threshold),200,['Cache-Control'=>'no-store']);
    }
    private function redactionSystemGet(Request $r,array $p): Response { return $this->redactionGet($r,$p); }
    private function redactionSystemSet(Request $r,array $p): Response { return $this->redactionSet($r,$p); }
    private function redactionSystemPreview(Request $r,array $p): Response { return $this->redactionPreview($r,$p); }
    private function redactionProjectGet(Request $r,array $p): Response { return $this->redactionGet($r,$p); }
    private function redactionProjectSet(Request $r,array $p): Response { return $this->redactionSet($r,$p); }
    private function redactionProjectPreview(Request $r,array $p): Response { return $this->redactionPreview($r,$p); }
    private function redactionSourceGet(Request $r,array $p): Response { return $this->redactionGet($r,$p); }
    private function redactionSourceSet(Request $r,array $p): Response { return $this->redactionSet($r,$p); }
    private function redactionSourcePreview(Request $r,array $p): Response { return $this->redactionPreview($r,$p); }
    /** Policy administration is deliberately restricted to global administrators. */
    private function redactionScope(Request $r,array $p): array
    {
        $this->session($p); $this->allow($p,'projects:manage');
        return [$r->parameter('project')===null?null:$this->id($r,'project'),$r->parameter('source')===null?null:$this->id($r,'source')];
    }
    private function redactionGet(Request $r,array $p): Response
    {
        [$project,$source]=$this->redactionScope($r,$p);
        return new JsonResponse((new \Cajeer\Logs\Log\RedactionPolicies($this->pdo))->get($project,$source),200,['Cache-Control'=>'no-store']);
    }
    private function redactionBody(Request $r,array $fields): \stdClass
    {
        $d=\Cajeer\Logs\Log\StrictEventJson::decode($r->body());
        if (!$d instanceof \stdClass || array_diff(array_keys(get_object_vars($d)),$fields)!==[] || !is_array($d->rules??null)) self::invalid('Invalid redaction policy.');
        \Cajeer\Logs\Log\RedactionPolicy::validate($d->rules);
        return $d;
    }
    private function redactionSet(Request $r,array $p): Response
    {
        [$project,$source]=$this->redactionScope($r,$p); $d=$this->redactionBody($r,['revision','rules']);
        if (!is_int($d->revision??null) || $d->revision<0) self::invalid('Invalid revision.');
        $result=(new \Cajeer\Logs\Log\RedactionPolicies($this->pdo))->set($project,$source,$d->revision,$d->rules);
        $this->audit($r,$p,'redaction.policy_changed',$source??$project,['project_id'=>$project,'source_id'=>$source,'revision'=>$result['revision'],'rule_count'=>count($d->rules)]);
        return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
    }
    private function redactionPreview(Request $r,array $p): Response
    {
        [$project,$source]=$this->redactionScope($r,$p); $d=$this->redactionBody($r,['rules','event']);
        if (!(($d->event??null) instanceof \stdClass)) self::invalid('Expected a sample event.');
        $event=(new \Cajeer\Logs\Log\IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode($d->event,JSON_THROW_ON_ERROR)));
        if (count($event)!==1) self::invalid('Expected a single sample event.');
        if ($project===null) $masked=(new \Cajeer\Logs\Log\RedactionPolicy($d->rules))->apply($event[0]);
        else {
            $repo=new \Cajeer\Logs\Log\RedactionPolicies($this->pdo); $repo->get($project,$source);
            $map=(new \Cajeer\Logs\Log\LogRepository($this->pdo))->resolveSources($event,$project,$source);
            $masked=$repo->apply($event,$project,$map,draft:['project'=>$project,'source'=>$source,'rules'=>$d->rules])[0];
        }
        return new JsonResponse(['event'=>$masked],200,['Cache-Control'=>'no-store']);
    }
    private function projectSigning(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage');
        return new JsonResponse($this->one('SELECT require_ingest_hmac FROM projects WHERE id=:id',['id'=>$this->id($r,'project')]));
    }
    private function setProjectSigning(Request $r,array $p): Response
    {
        $this->session($p); $this->allow($p,'projects:manage'); $id=$this->id($r,'project');
        $d=$this->body($r,['required']); if (!is_bool($d['required']??null)) self::invalid();
        $row=$this->one('UPDATE projects SET require_ingest_hmac=CAST(:required AS boolean),revision=revision+1,updated_at=clock_timestamp() WHERE id=:id RETURNING require_ingest_hmac,revision',['required'=>$d['required']?'true':'false','id'=>$id]);
        $row['revision']=(string)$row['revision'];
        $this->audit($r,$p,'project.signing_policy',$id,['required'=>$d['required']]); return new JsonResponse($row);
    }
    private function signing(): \Cajeer\Logs\Security\IngestSigning { return new \Cajeer\Logs\Security\IngestSigning($this->pdo,$this->app->config()->get('security.totp_key')); }
    private function tokenSigning(Request $r,array $p): Response
    {
        return new JsonResponse($this->signing()->metadata($this->tokenProject($r,$p,false,'tokens:read'),$this->id($r,'token')));
    }
    private function configureSigning(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'tokens:signing'); $id=$this->id($r,'token'); $d=$this->body($r,['mode','overlap_seconds','expected_key_id']);
        if (!is_string($d['mode']??null)) self::invalid();
        $expected=$d['expected_key_id']??null; if ($expected!==null) { if (!is_string($expected)) self::invalid(); $expected=self::uuid($expected); }
        $result=$this->signing()->configure($project,$id,$d['mode'],TokenRepository::grace($d['overlap_seconds']??0),$expected);
        $this->audit($r,$p,'token.signing_changed',$id,['project_id'=>$project,'mode'=>$d['mode'],'key_id'=>$result['current_key_id']]);
        return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
    }
    private function retireSigningKey(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'tokens:signing'); $id=$this->id($r,'token');
        $d=$this->body($r,['expected_key_id','expected_previous_key_id']);
        $current=self::uuid(self::str($d,'expected_key_id',36));
        $previous=self::uuid(self::str($d,'expected_previous_key_id',36));
        $result=$this->signing()->retirePrevious($project,$id,$current,$previous);
        $this->audit($r,$p,'token.signing_previous_retired',$id,['project_id'=>$project,'key_id'=>$previous]);
        return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
    }
    private function tokenList(Request $r,?string $project): Response
    {
        $query='SELECT '.TokenRepository::FIELDS.' FROM api_tokens WHERE project_id IS NOT DISTINCT FROM CAST(:project AS uuid)'; $params=['project'=>$project];
        $status=$r->query['status']??'all';
        $query.=match($status){'all'=>'','active'=>" AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())",'revoked'=>' AND revoked_at IS NOT NULL','expired'=>' AND revoked_at IS NULL AND expires_at<=clock_timestamp()', 'expiring'=>" AND revoked_at IS NULL AND expires_at>clock_timestamp() AND expires_at<=clock_timestamp()+interval '7 days'",default=>self::invalid('Invalid token status.')};
        foreach (['source_id','service_account_id'] as $field) if(isset($r->query[$field])) { if($project===null||!is_string($r->query[$field])) self::invalid($field); $params[$field]=self::uuid($r->query[$field]); $query.=' AND '.$field.'=:'.$field; }
        if (isset($r->query['q'])) {
            $q=$r->query['q']; if (!is_string($q)||strlen($q)>255||str_contains($q,"\0")) self::invalid('Invalid token search.');
            $params['q']=trim($q); $query.=" AND (strpos(lower(name),lower(:q))>0 OR strpos(id::text,lower(:q_id))>0)"; $params['q_id']=$params['q'];
        }
        if (isset($r->query['scope'])) {
            $scope=$r->query['scope']; TokenRepository::scopes([$scope],$project);
            $query.=' AND jsonb_exists(scopes,CAST(:scope AS text))'; $params['scope']=$scope;
        }
        return $this->page($r,$query,$params);
    }
    private function detailToken(Request $r,?string $project): Response
    {
        $id=$this->id($r,'token');
        $row=TokenRepository::decode($this->one('SELECT '.TokenRepository::FIELDS.' FROM api_tokens WHERE id=:id AND project_id IS NOT DISTINCT FROM CAST(:project AS uuid)',['id'=>$id,'project'=>$project]));
        $row['predecessor_id']=$this->sql('SELECT id FROM api_tokens WHERE rotated_to_id=:id AND project_id IS NOT DISTINCT FROM CAST(:project AS uuid)',['id'=>$id,'project'=>$project])->fetchColumn()?:null;
        $row['signing']=null;
        if ($project!==null && in_array('logs:write',$row['scopes'],true)) {
            $meta=$this->signing()->metadata($project,$id);
            $policy=$this->one('SELECT require_ingest_hmac FROM projects WHERE id=:id',['id'=>$project]);
            $meta['effective_required']=\Cajeer\Logs\Database\PostgresBoolean::required($meta['required'])||\Cajeer\Logs\Database\PostgresBoolean::required($policy['require_ingest_hmac'])||$this->app->config()->get('ingest.require_hmac');
            $meta['mode']=$meta['current_key_id']!==null?'separate':($meta['effective_required']?'bearer':'optional');
            $row['signing']=$meta;
        }
        return new JsonResponse($row,200,['Cache-Control'=>'no-store']);
    }
    private function tokenDetail(Request $r,array $p): Response { return $this->detailToken($r,$this->tokenProject($r,$p,false,'tokens:read')); }
    private function systemTokenDetail(Request $r,array $p): Response { return $this->detailToken($r,$this->tokenProject($r,$p,true)); }
    private function deadLetterManager(): \Cajeer\Logs\Queue\DeadLetterManager {
        return new \Cajeer\Logs\Queue\DeadLetterManager($this->pdo,\Cajeer\Logs\Queue\RetryPolicy::fromConfig($this->app->config()));
    }
    private function queueProject(Request $r,array $p,string $permission='queue:read'): string {
        $this->session($p); $project=$this->id($r,'project'); $this->allow($p,'queue:read',$project);
        if ($permission!=='queue:read') $this->allow($p,$permission,$project);
        $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); return $project;
    }
    private function deadLetters(Request $r,array $p): Response {
        return new JsonResponse($this->deadLetterManager()->page($this->queueProject($r,$p),$r->query),200,['Cache-Control'=>'no-store']);
    }
    private function queueJob(Request $r,array $p): Response {
        return new JsonResponse($this->deadLetterManager()->detail($this->queueProject($r,$p),$r->parameter('job')??''),200,['Cache-Control'=>'no-store']);
    }
    private function changeDeadLetters(Request $r,array $p,string $action): Response {
        $project=$this->queueProject($r,$p,'queue:'.$action); $d=$this->body($r,['items','confirm']);
        if (($d['confirm']??null)!==true) self::invalid('Explicit confirmation required.');
        $object=json_decode($r->body(),false,64,JSON_THROW_ON_ERROR);
        if (!is_array($object->items??null)) self::invalid('items must be a JSON array.');
        return new JsonResponse($this->deadLetterManager()->change($project,$action,$d['items']??[],$p['id'],$r->requestId),200,['Cache-Control'=>'no-store']);
    }
    private function retryDeadLetters(Request $r,array $p): Response {return $this->changeDeadLetters($r,$p,'retry');}
    private function discardDeadLetters(Request $r,array $p): Response {return $this->changeDeadLetters($r,$p,'discard');}
    private function queueDispatchStatus(Request $r,array $p): Response {
        $this->session($p); $this->allow($p,'system:read');
        return new JsonResponse((new \Cajeer\Logs\Queue\QueueOperations($this->pdo))->status(),200,['Cache-Control'=>'no-store']);
    }
    private function queueDispatchChange(Request $r,array $p,bool $pause): Response {
        $this->session($p); $this->allow($p,'system:read');
        $d=$this->body($r,['confirm','reason']);
        if (($d['confirm']??null)!==true || (!$pause && array_key_exists('reason',$d))) self::invalid('Explicit confirmation required.');
        try { $reason=\Cajeer\Logs\Queue\QueueOperations::reason($d['reason']??null); }
        catch (\InvalidArgumentException) { self::invalid('Invalid queue dispatch reason.'); }
        $result=(new \Cajeer\Logs\Queue\QueueOperations($this->pdo))->setPaused($pause,$reason,$p['id'],$r->requestId);
        return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
    }
    private function pauseQueueDispatch(Request $r,array $p): Response { return $this->queueDispatchChange($r,$p,true); }
    private function resumeQueueDispatch(Request $r,array $p): Response { return $this->queueDispatchChange($r,$p,false); }
    private function projectSecurityAudit(Request $r,array $p): Response { $this->session($p); $project=$this->id($r,'project'); $this->allow($p,'audit:read',$project); $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); return $this->securityAudit($r,$project,$p); }
    private function systemSecurityAudit(Request $r,array $p): Response { return $this->securityAudit($r,$this->tokenProject($r,$p,true),$p); }
    private function securityAudit(Request $r,?string $project,array $p): Response
    {
        // Explicit scope is assigned at insertion, including migrated legacy events.
        $where='TRUE'; $params=[];
        if ($project!==null) { $where='a.project_id=:project'; $params['project']=$project; }
        foreach (['from','to'] as $field) if (isset($r->query[$field])) {
            $value=$r->query[$field];
            if (!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$value)) self::invalid('Invalid audit UTC timestamp.');
            try { $time=new \DateTimeImmutable($value); } catch (\Exception) { self::invalid('Invalid audit UTC timestamp.'); }
            if ($time->format('Y-m-d\TH:i:s')!==substr($value,0,19)) self::invalid('Invalid audit UTC timestamp.');
            $where.=' AND a.created_at'.($field==='from'?'>=':'<').'CAST(:'.$field.' AS timestamptz)'; $params[$field]=$value;
        }
        if (isset($params['from'],$params['to']) && new \DateTimeImmutable($params['from'])>=new \DateTimeImmutable($params['to'])) self::invalid('Invalid audit time range.');
        foreach (['result'=>['success','failure','denied'],'actor_type'=>['user','token','system','anonymous']] as $field=>$allowed) if (isset($r->query[$field])) {
            if (!in_array($r->query[$field],$allowed,true)) self::invalid('Invalid '.$field);
            $where.=' AND a.'.$field.'=:'.$field; $params[$field]=$r->query[$field];
        }
        $format=$r->query['format']??'json';
        if (!in_array($format,['json','ndjson'],true)) self::invalid('Invalid audit format.');
        foreach (['actor_id','target'] as $field) if (isset($r->query[$field])) {
            if (!is_string($r->query[$field])) self::invalid($field);
            $params[$field]=$field==='target'&&ctype_digit($r->query[$field])?\Cajeer\Logs\Queue\DeadLetterManager::id($r->query[$field]):self::uuid($r->query[$field]); $where.=" AND a.$field=:$field";
        }
        if (isset($r->query['action'])) {
            $action=$r->query['action']; if (!is_string($action)||!preg_match('/^[a-z_]+\.[a-z_]+$/D',$action)||strlen($action)>80) self::invalid('Invalid audit action.');
            $where.=' AND a.action=:action'; $params['action']=$action;
        }
        $limit=filter_var($r->query['limit']??50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($limit===false) self::invalid('limit must be 1..100.');
        if (isset($r->query['cursor'])) {
            if (!is_string($r->query['cursor'])) self::invalid('Invalid cursor.');
            $anchor=$this->one('SELECT a.created_at,a.id FROM audit_log a WHERE '.$where.' AND a.id=:cursor',$params+['cursor'=>self::uuid($r->query['cursor'])]);
            $where.=' AND (a.created_at,a.id)<(CAST(:anchor_time AS timestamptz),CAST(:anchor_id AS uuid))';
            $params+=['anchor_time'=>$anchor['created_at'],'anchor_id'=>$anchor['id']];
        }
        // The global sequence reveals cross-project volume; only system audit exposes it.
        $chainFields=$project===null?'a.chain_position,a.event_hash,':'';
        $query="SELECT a.id,a.actor_id,a.action,a.target,a.created_at,a.context->>'request_id' AS request_id,a.project_id,a.result,a.actor_type,{$chainFields}a.context->>'operation' AS operation,a.context->>'key_id' AS key_id,a.context->>'successor_id' AS successor_id,a.context->>'source_id' AS source_id,a.context->>'service_account_id' AS service_account_id,a.context->>'overlap_seconds' AS overlap_seconds,a.context->>'mode' AS mode,a.context->>'count' AS count,a.context->>'revoked' AS revoked,a.context->>'role_before' AS role_before,a.context->>'role_after' AS role_after,a.context->>'project_role' AS project_role,a.context->>'required' AS required,a.context->>'blocked_before' AS blocked_before,a.context->>'blocked_after' AS blocked_after,a.context->>'password_reset' AS password_reset,a.context->>'sessions_revoked' AS sessions_revoked,CASE WHEN a.action='role.created' THEN a.context->>'permissions' END AS role_permissions,CASE WHEN a.action='role.updated' THEN a.context->>'permissions_before' END AS permissions_before,CASE WHEN a.action='role.updated' THEN a.context->>'permissions_after' END AS permissions_after FROM audit_log a WHERE ";
        $rows=$this->sql($query.$where.' ORDER BY a.created_at DESC,a.id DESC LIMIT '.($limit+1),$params)->fetchAll();
        $next=null; if (count($rows)>$limit) { array_pop($rows); $next=end($rows)['id']; }
        if ($format==='ndjson') {
            $body=''; foreach ($rows as $row) $body.=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
            $headers=['Cache-Control'=>'no-store','Content-Type'=>'application/x-ndjson; charset=utf-8','Content-Disposition'=>'attachment; filename="cajeerlogs-audit.ndjson"'];
            if ($next!==null) $headers['X-Next-Cursor']=$next;
            $this->audit($r,$p,'audit.exported',$project,['project_id'=>$project,'count'=>count($rows)]);
            return new Response($body,200,$headers);
        }
        return new JsonResponse(['items'=>$rows,'next_cursor'=>$next],200,['Cache-Control'=>'no-store']);
    }
    private function tokenProject(Request $r,array $p,bool $system=false,string $permission='tokens:read'): ?string
    {
        $this->session($p);
        if($system) { $this->allow($p,'users:manage'); return null; }
        $project=$this->id($r,'project'); $this->allow($p,$permission,$project); $this->one('SELECT id FROM projects WHERE id=:id',['id'=>$project]); return $project;
    }
    private function tokens(Request $r,array $p): Response { return $this->tokenList($r,$this->tokenProject($r,$p,false,'tokens:read')); }
    private function systemTokens(Request $r,array $p): Response { return $this->tokenList($r,$this->tokenProject($r,$p,true)); }
    private function grantScopes(array $p,?string $project,array $scopes): void
    {
        if ($project===null) return; // system credential issuance remains administrator-only
        foreach ($scopes as $scope) $this->allow($p,$scope,$project);
        // A read-scoped bearer can export through the legacy API; do not mint it for a role denied export.
        if (in_array('logs:read',$scopes,true)) $this->allow($p,'logs:export',$project);
    }
    private function issue(Request $r,array $p,?string $project): Response
    {
        $d=$this->body($r,['name','scopes','source_id','expires_in','description','requests_per_minute','service_account_id','signing_mode']);
        $name=self::str($d,'name'); $scopes=TokenRepository::scopes($d['scopes']??null,$project); $this->grantScopes($p,$project,$scopes); $ttl=TokenRepository::ttl($d['expires_in']??2592000);
        $source=null; if (isset($d['source_id'])) { if ($project===null||!is_string($d['source_id'])) self::invalid(); $source=self::uuid($d['source_id']); $this->one('SELECT id FROM sources WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$source]); }
        $account=null; if(isset($d['service_account_id'])) { if($project===null||!is_string($d['service_account_id'])) self::invalid(); $account=self::uuid($d['service_account_id']); }
        $meta=TokenRepository::changes(['name'=>$name,'description'=>array_key_exists('description',$d)?$d['description']:'','requests_per_minute'=>$d['requests_per_minute']??null]);
        $result=(new TokenRepository($this->pdo))->issue($name,$scopes,$project,$source,$ttl,$p['id'],$account,$meta['description'],$meta['requests_per_minute']);
        if (array_key_exists('signing_mode',$d)) {
            if ($project!==null) $this->allow($p,'tokens:signing',$project);
            if ($project===null || !in_array('logs:write',$scopes,true) || !is_string($d['signing_mode'])) self::invalid('Signing mode requires an ingest credential.');
            $result['signing']=$this->signing()->configure($project,$result['id'],$d['signing_mode'],0);
        }
        $this->audit($r,$p,'token.created',$result['id'],['project_id'=>$project,'service_account_id'=>$account,'scopes'=>$scopes]); return new JsonResponse($result,201,['Cache-Control'=>'no-store']);
    }
    private function createToken(Request $r,array $p): Response { return $this->issue($r,$p,$this->tokenProject($r,$p,false,'tokens:create')); }
    private function createSystemToken(Request $r,array $p): Response { return $this->issue($r,$p,$this->tokenProject($r,$p,true)); }
    private function revoke(Request $r,array $p,?string $project): Response
    {
        $id=$this->id($r,'token'); $this->one('SELECT id FROM api_tokens WHERE id=:id AND project_id IS NOT DISTINCT FROM CAST(:project AS uuid)',['id'=>$id,'project'=>$project]);
        if((new TokenRepository($this->pdo))->revoke($id)) $this->audit($r,$p,'token.revoked',$id,['project_id'=>$project]); return new Response('',204);
    }
    private function revokeToken(Request $r,array $p): Response { return $this->revoke($r,$p,$this->tokenProject($r,$p,false,'tokens:revoke')); }
    private function revokeSystemToken(Request $r,array $p): Response { return $this->revoke($r,$p,$this->tokenProject($r,$p,true)); }
    private function changeToken(Request $r,array $p,?string $project): Response
    {
        $d=$this->body($r,['revision','name','description','requests_per_minute']); $revision=TokenRepository::revision($d['revision']??null); unset($d['revision']);
        $id=$this->id($r,'token'); $result=(new TokenRepository($this->pdo))->update($project,$id,$revision,$d);
        $this->audit($r,$p,'token.updated',$id,['project_id'=>$project,'fields'=>array_keys($d),'revision'=>$result['revision']]); return new JsonResponse($result);
    }
    private function updateToken(Request $r,array $p): Response { return $this->changeToken($r,$p,$this->tokenProject($r,$p,false,'tokens:update')); }
    private function updateSystemToken(Request $r,array $p): Response { return $this->changeToken($r,$p,$this->tokenProject($r,$p,true)); }
    private function rotate(Request $r,array $p,?string $project): Response
    {
        $d=$this->body($r,['revision','expires_in','overlap_seconds','scopes']); $id=$this->id($r,'token');
        $scopes=array_key_exists('scopes',$d)?TokenRepository::scopes($d['scopes'],$project):null;
        if ($project!==null && $scopes===null) {
            $old=$this->one('SELECT scopes FROM api_tokens WHERE id=:id AND project_id=:project',['id'=>$id,'project'=>$project]);
            $scopes=TokenRepository::decode($old)['scopes'];
        }
        if ($scopes!==null) $this->grantScopes($p,$project,$scopes);
        $result=(new TokenRepository($this->pdo))->rotate($project,$id,TokenRepository::revision($d['revision']??null),TokenRepository::ttl($d['expires_in']??2592000),TokenRepository::grace($d['overlap_seconds']??0),$p['id'],$scopes,$this->app->config()->get('security.totp_key'));
        $this->audit($r,$p,'token.rotated',$id,['project_id'=>$project,'successor_id'=>$result['id'],'overlap_seconds'=>$d['overlap_seconds']??0,'scopes'=>$result['scopes']]); return new JsonResponse($result,201,['Cache-Control'=>'no-store']);
    }
    private function rotateToken(Request $r,array $p): Response { return $this->rotate($r,$p,$this->tokenProject($r,$p,false,'tokens:rotate')); }
    private function rotateSystemToken(Request $r,array $p): Response { return $this->rotate($r,$p,$this->tokenProject($r,$p,true)); }
    private function bulkRevoke(Request $r,array $p,?string $project): Response
    {
        $d=$this->body($r,['confirm','source_id','service_account_id']); if(($d['confirm']??null)!==true) self::invalid('Explicit confirmation required.');
        $source=$account=null;
        if(array_key_exists('source_id',$d)) { if($project===null||!is_string($d['source_id'])) self::invalid('source_id'); $source=self::uuid($d['source_id']); $this->one('SELECT id FROM sources WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$source]); }
        if(array_key_exists('service_account_id',$d)) { if($project===null||$source!==null||!is_string($d['service_account_id'])) self::invalid('service_account_id'); $account=self::uuid($d['service_account_id']); $this->one('SELECT id FROM service_accounts WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$account]); }
        $count=(new TokenRepository($this->pdo))->revokeAll($project,$source,$account);
        $this->audit($r,$p,'token.bulk_revoked',$project,['project_id'=>$project,'source_id'=>$source,'service_account_id'=>$account,'count'=>$count]); return new JsonResponse(['revoked'=>$count]);
    }
    private function revokeProjectTokens(Request $r,array $p): Response { return $this->bulkRevoke($r,$p,$this->tokenProject($r,$p,false,'tokens:revoke')); }
    private function revokeAllSystemTokens(Request $r,array $p): Response { return $this->bulkRevoke($r,$p,$this->tokenProject($r,$p,true)); }
    private function serviceAccounts(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'services:read'); $query='SELECT id,project_id,name,created_by,created_at,disabled_at FROM service_accounts WHERE project_id=:project'; $params=['project'=>$project];
        $query.=match($r->query['status']??'all') {'all'=>'','active'=>' AND disabled_at IS NULL','disabled'=>' AND disabled_at IS NOT NULL',default=>self::invalid('Invalid account status.')};
        if (isset($r->query['q'])) { $q=$r->query['q']; if(!is_string($q)||strlen($q)>255||str_contains($q,"\0")) self::invalid('Invalid account search.'); $query.=' AND (strpos(lower(name),lower(:q))>0 OR strpos(id::text,lower(:qid))>0)'; $params+=['q'=>trim($q),'qid'=>trim($q)]; }
        return $this->page($r,$query,$params);
    }
    private function serviceAccountDetail(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'services:read'); $id=$this->id($r,'account');
        $row=$this->one('SELECT id,project_id,name,created_by,created_at,disabled_at FROM service_accounts WHERE id=:id AND project_id=:project',['id'=>$id,'project'=>$project]);
        $stats=$this->one("SELECT count(*) AS total_tokens,count(*) FILTER (WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())) AS active_tokens,max(last_used_at) AS last_used_at FROM api_tokens WHERE service_account_id=:id AND project_id=:project",['id'=>$id,'project'=>$project]);
        foreach (['total_tokens','active_tokens'] as $key) $stats[$key]=(int)$stats[$key];
        return new JsonResponse($row+$stats,200,['Cache-Control'=>'no-store']);
    }
    private function createServiceAccount(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'services:manage'); $d=$this->body($r,['name']); $name=TokenRepository::changes(['name'=>self::str($d,'name')])['name'];
        $row=$this->sql('INSERT INTO service_accounts(project_id,name,created_by) VALUES (:project,:name,:actor) RETURNING id,project_id,name,created_by,created_at,disabled_at',['project'=>$project,'name'=>$name,'actor'=>$p['id']])->fetch();
        $this->audit($r,$p,'service_account.created',$row['id'],['project_id'=>$project]); return new JsonResponse($row,201);
    }
    private function disableServiceAccount(Request $r,array $p): Response
    {
        $project=$this->tokenProject($r,$p,false,'services:manage'); $this->allow($p,'tokens:revoke',$project); $id=$this->id($r,'account'); $d=$this->body($r,['confirm']); if(($d['confirm']??null)!==true) self::invalid('Explicit confirmation required.');
        $row=$this->one('UPDATE service_accounts SET disabled_at=COALESCE(disabled_at,clock_timestamp()) WHERE id=:id AND project_id=:project RETURNING id,project_id,name,created_by,created_at,disabled_at',['id'=>$id,'project'=>$project]);
        $count=(new TokenRepository($this->pdo))->revokeAll($project,null,$id); $this->audit($r,$p,'service_account.disabled',$id,['project_id'=>$project,'revoked'=>$count]); return new JsonResponse($row+['revoked'=>$count]);
    }
    private function roleAdmin(array $p): void { $this->session($p); $this->allow($p,'roles:manage'); }
    private const ROLE_FIELDS='id,name,scope,permissions,revision,created_at,updated_at';
    private function roleRow(array $row): array { $row['permissions']=json_decode($row['permissions'],true,16,JSON_THROW_ON_ERROR); $row['revision']=(int)$row['revision']; return $row; }
    private function roles(Request $r,array $p): Response
    {
        $this->roleAdmin($p);
        $rows=$this->sql('SELECT '.self::ROLE_FIELDS.' FROM rbac_roles ORDER BY scope,name,id')->fetchAll();
        return new JsonResponse(['items'=>array_map($this->roleRow(...),$rows),'permissions'=>\Cajeer\Logs\Security\Rbac::PROJECT,'builtins'=>array_combine(['administrator','operator','viewer'],array_map(\Cajeer\Logs\Security\Rbac::permissions(...),['administrator','operator','viewer']))],200,['Cache-Control'=>'no-store']);
    }
    private function roleInput(Request $r,bool $create): array
    {
        $d=$this->body($r,$create?['name','scope','permissions']:['name','permissions','revision']);
        $name=trim(self::str($d,'name',120)); if ($name==='') self::invalid('Role name required.');
        $object=json_decode($r->body(),false,64,JSON_THROW_ON_ERROR);
        if (!is_array($object->permissions??null)) self::invalid('permissions must be an array.');
        $permissions=\Cajeer\Logs\Security\Rbac::validate($d['permissions']??null);
        return ['name'=>$name,'permissions'=>json_encode($permissions,JSON_THROW_ON_ERROR)];
    }
    private function createRole(Request $r,array $p): Response
    {
        $this->roleAdmin($p); $params=$this->roleInput($r,true); $d=$r->json();
        if (!in_array($d['scope']??null,['global','project'],true)) self::invalid('Invalid role scope.');
        if ((int)$this->sql('SELECT count(*) FROM rbac_roles')->fetchColumn()>=200) throw new HttpException(409,'role_limit','Role limit reached.');
        $row=$this->roleRow($this->one('INSERT INTO rbac_roles(name,scope,permissions) VALUES(:name,:scope,CAST(:permissions AS jsonb)) RETURNING '.self::ROLE_FIELDS,$params+['scope'=>$d['scope']]));
        $this->audit($r,$p,'role.created',$row['id'],['scope'=>$row['scope'],'permissions'=>$row['permissions']]); return new JsonResponse($row,201);
    }
    private function roleRevision(array $d): int
    { $revision=$d['revision']??null; if (!is_int($revision)||$revision<1||$revision>2147483646) self::invalid('Valid role revision required.'); return $revision; }
    private function updateRole(Request $r,array $p): Response
    {
        $this->roleAdmin($p); $id=$this->id($r,'role'); $params=$this->roleInput($r,false);
        $old=$this->roleRow($this->one('SELECT '.self::ROLE_FIELDS.' FROM rbac_roles WHERE id=:id FOR UPDATE',['id'=>$id]));
        if ($old['revision']!==$this->roleRevision($r->json())) throw new HttpException(409,'role_changed','Role changed. Reload before retrying.');
        $row=$this->roleRow($this->one('UPDATE rbac_roles SET name=:name,permissions=CAST(:permissions AS jsonb),revision=revision+1,updated_at=clock_timestamp() WHERE id=:id RETURNING '.self::ROLE_FIELDS,$params+['id'=>$id]));
        $this->audit($r,$p,'role.updated',$id,['permissions_before'=>$old['permissions'],'permissions_after'=>$row['permissions']]); return new JsonResponse($row);
    }
    private function deleteRole(Request $r,array $p): Response
    {
        $this->roleAdmin($p); $id=$this->id($r,'role'); $d=$this->body($r,['revision']);
        $row=$this->roleRow($this->one('SELECT '.self::ROLE_FIELDS.' FROM rbac_roles WHERE id=:id FOR UPDATE',['id'=>$id]));
        if ($row['revision']!==$this->roleRevision($d)) throw new HttpException(409,'role_changed','Role changed. Reload before retrying.');
        // Never delete an assigned role: doing so could silently broaden access.
        // The role row is locked FOR UPDATE above; concurrent FK inserts wait.
        if ($this->sql('SELECT 1 FROM users WHERE rbac_role_id=:id LIMIT 1',['id'=>$id])->fetchColumn()!==false
            || $this->sql('SELECT 1 FROM project_members WHERE rbac_role_id=:id LIMIT 1',['id'=>$id])->fetchColumn()!==false) {
            throw new HttpException(409,'role_in_use','Role has active assignments and cannot be deleted.');
        }
        try {
            $this->sql('DELETE FROM rbac_roles WHERE id=:id',['id'=>$id]);
        } catch (\PDOException $error) {
            if ((string)($error->errorInfo[0]??$error->getCode())==='23503') {
                throw new HttpException(409,'role_in_use','Role has active assignments and cannot be deleted.');
            }
            throw $error;
        }
        $this->audit($r,$p,'role.deleted',$id); return new Response('',204);
    }
    private function assignment(Request $r,string $scope): ?string
    {
        $d=$this->body($r,['role_id','expected_role_id']); if (!array_key_exists('role_id',$d)||!array_key_exists('expected_role_id',$d)) self::invalid('role_id and expected_role_id required.');
        if ($d['role_id']===null) return null;
        $id=self::uuid(self::str($d,'role_id',36));
        $row=$this->one('SELECT scope FROM rbac_roles WHERE id=:id',['id'=>$id]);
        if ($row['scope']!==$scope) self::invalid('Role scope mismatch.'); return $id;
    }
    private function assignGlobalRole(Request $r,array $p): Response
    {
        $this->roleAdmin($p); $user=$this->id($r,'user'); $role=$this->assignment($r,'global');
        $old=$this->one('SELECT role,rbac_role_id FROM users WHERE id=:id FOR UPDATE',['id'=>$user]);
        if ($old['role']==='administrator') self::invalid('Administrator roles are immutable.');
        $expected=$r->json()['expected_role_id'];
        if ($expected!==null) $expected=self::uuid(self::str(['id'=>$expected],'id',36));
        if ($expected!==$old['rbac_role_id']) throw new HttpException(409,'role_assignment_changed','Assignment changed. Reload before retrying.');
        $this->sql('UPDATE users SET rbac_role_id=:role,updated_at=clock_timestamp() WHERE id=:id',['role'=>$role,'id'=>$user]);
        $this->audit($r,$p,'role.global_assigned',$user,['role_before'=>$old['rbac_role_id'],'role_after'=>$role]); return new Response('',204);
    }
    private function assignProjectRole(Request $r,array $p): Response
    {
        $this->roleAdmin($p); $project=$this->id($r,'project'); $user=$this->id($r,'user'); $role=$this->assignment($r,'project');
        $old=$this->one('SELECT m.rbac_role_id,u.role FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=:project AND m.user_id=:user FOR UPDATE OF m',['project'=>$project,'user'=>$user]);
        if ($old['role']==='administrator') self::invalid('Administrator roles are immutable.');
        $expected=$r->json()['expected_role_id'];
        if ($expected!==null) $expected=self::uuid(self::str(['id'=>$expected],'id',36));
        if ($expected!==$old['rbac_role_id']) throw new HttpException(409,'role_assignment_changed','Assignment changed. Reload before retrying.');
        $this->sql('UPDATE project_members SET rbac_role_id=:role WHERE project_id=:project AND user_id=:user',['role'=>$role,'project'=>$project,'user'=>$user]);
        $this->audit($r,$p,'role.project_assigned',$user,['project_id'=>$project,'role_before'=>$old['rbac_role_id'],'role_after'=>$role]); return new Response('',204);
    }
    private function report(Request $r,array $p): Response { $this->allow($p,'system:read'); return (new SystemController($this->app->container()->get(SystemReport::class)))->report(); }
}
