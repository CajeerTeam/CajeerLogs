<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\{DatabaseException,PostgresBoolean,TransactionManager,MigrationRunner};
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\{Identity,IngestAuthorization,RateLimiter,Skif4erOidc};
use PDO;

final class Skif4erController
{
    private const COOKIE='__Host-cajeerlogs_oidc';
    private Identity $identity;
    private string $origin;
    private PDO $pdo;
    private ?Skif4erOidc $oidc=null;
    private bool $enabled;
    private \Cajeer\Logs\Database\MigrationGuard $guard;
    public const ROUTES=['GET /auth/skif4er/status'=>'status','GET /auth/skif4er/start'=>'start','GET /auth/skif4er/callback'=>'callback','POST /auth/skif4er/finish'=>'finish','GET /auth/skif4er/account'=>'account','POST /auth/skif4er/link'=>'link','POST /auth/skif4er/link/finish'=>'linkFinish','POST /auth/skif4er/unlink'=>'unlink','POST /auth/skif4er/revoke'=>'revoke'];
    public static function register(Router $router,Application $app,string $prefix): void {
        foreach(self::ROUTES as $route=>$action) {
            [$method,$path]=explode(' ',$route,2);
            $router->add($method,$prefix.$path,static function(Request $r) use($app,$action): Response {
                $enabled=$app->config()->get('skif4er.enabled')===true;
                if ($action==='status') {
                    $ready=false;
                    if ($enabled&&$r->secureTransport) {
                        try { self::configuredClient($app); $ready=true; } catch (\Throwable) {}
                    }
                    return new JsonResponse(['enabled'=>$ready],200,['Cache-Control'=>'no-store']);
                }
                if (!$enabled && !in_array($action,['account','unlink','revoke'],true)) throw new HttpException(404,'not_found','SkiF4er ID is disabled.');
                if (!$r->secureTransport) throw new HttpException(403,'https_required','HTTPS is required.');
                if (in_array($action,['account','link','linkFinish','unlink','revoke'],true) && Identity::credentials($r)['kind']!=='session') Identity::forbidden();
                try { return (new self($app,in_array($action,['start','callback','link'],true)))->$action($r); }
                catch (HttpException $e) { throw $e; }
                catch (DatabaseException|\PDOException $e) { throw new HttpException(503,'database_unavailable','Identity storage unavailable.'); }
                catch (\Throwable $e) { throw new HttpException(503,'external_auth_unavailable','SkiF4er ID authentication unavailable.'); }
            });
        }
    }
    public static function configuredClient(Application $app): Skif4erOidc {
        $cfg=$app->config(); $url=rtrim($cfg->get('app.url'),'/'); $parts=parse_url($url);
        if (!$parts||($parts['scheme']??null)!=='https'||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||($parts['path']??'')!=='') throw new \InvalidArgumentException('SkiF4er ID requires a bare HTTPS APP_URL.');
        return new Skif4erOidc($cfg->get('skif4er.client_id'),$cfg->get('skif4er.client_secret'),$url.$cfg->get('api.prefix').'/auth/skif4er/callback');
    }
    private function __construct(Application $app,bool $needsProvider) {
        $cfg=$app->config(); $url=rtrim($cfg->get('app.url'),'/'); $parts=parse_url($url);
        if (!$parts||($parts['scheme']??null)!=='https'||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||($parts['path']??'')!=='') throw new \InvalidArgumentException('SkiF4er ID requires a bare HTTPS APP_URL.');
        $this->enabled=$cfg->get('skif4er.enabled')===true;
        if ($needsProvider) $this->oidc=self::configuredClient($app);
        $this->pdo=$app->container()->get(PDO::class); $this->guard=$app->container()->get(MigrationRunner::class)->guard();
        $this->origin=$url; $this->identity=Identity::fromConfig($this->pdo,true,$url,$cfg);
    }
    private function cookie(string $secret): string { return self::COOKIE.'='.$secret.'; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=300'; }
    private function browser(Request $r): string {
        $matches=[]; foreach(explode(';',$r->header('Cookie','')??'') as $part) { $p=explode('=',trim($part),2); if ($p[0]===self::COOKIE) $matches[]=$p[1]??''; }
        if (count($matches)!==1||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$matches[0])) Identity::unauthorized();
        return hash('sha256',$matches[0]);
    }
    private function redirect(string $location,?string $cookie=null): Response {
        $headers=['Location'=>$location,'Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer']; if ($cookie!==null) $headers['Set-Cookie']=$cookie;
        return new Response('',303,$headers);
    }
    private function limit(Request $r): void { (new RateLimiter($this->pdo))->enforce('skif4er-login-ip:'.IngestAuthorization::peerKey($r),30,300); }
    private function start(Request $r): Response {
        $this->identity->origin($r); $this->limit($r);
        $d=$this->oidc->discover(); $browser=Skif4erOidc::random(); $state=Skif4erOidc::random(); $nonce=Skif4erOidc::random(); $verifier=Skif4erOidc::random();
        $this->identity->sql("INSERT INTO skif4er_login_flows(browser_hash,state_hash,nonce,verifier,expires_at) VALUES (:browser,:state,:nonce,:verifier,clock_timestamp()+interval '5 minutes')",['browser'=>hash('sha256',$browser),'state'=>hash('sha256',$state),'nonce'=>$nonce,'verifier'=>$verifier]);
        return $this->redirect($this->oidc->authorize($d,$state,$nonce,$verifier),$this->cookie($browser));
    }
    private function callback(Request $r): Response {
        $this->limit($r);
        $link=false;
        try {
            $browser=$this->browser($r); $state=$r->query['state']??null;
            if (!is_string($state)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$state)) Identity::unauthorized();
            // Autocommit deletion consumes the authorization attempt even if exchange fails.
            $flow=$this->identity->sql('DELETE FROM skif4er_login_flows WHERE browser_hash=:browser AND state_hash=:state AND expires_at>clock_timestamp() RETURNING nonce,verifier,link_session_id',['browser'=>$browser,'state'=>hash('sha256',$state)])->fetch();
            $link=$flow && $flow['link_session_id']!==null;
            if (!$flow||isset($r->query['error'])||!is_string($code=$r->query['code']??null)|| (isset($r->query['iss'])&&$r->query['iss']!==Skif4erOidc::ISSUER)) Identity::unauthorized();
            $claims=$this->oidc->exchange($code,$flow['verifier'],$flow['nonce']);
            if ($link) {
                $next=Skif4erOidc::random();
                $this->identity->sql("INSERT INTO skif4er_login_flows(browser_hash,subject,user_id,link_session_id,verified_email,expires_at) SELECT :browser,:subject,s.user_id,s.id,:email,clock_timestamp()+interval '5 minutes' FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.id=:session AND s.expires_at>clock_timestamp() AND u.blocked_at IS NULL",['browser'=>hash('sha256',$next),'subject'=>$claims['sub'],'session'=>$flow['link_session_id'],'email'=>$claims['email']]);
                return $this->redirect('/admin/?skif4er=link#settings',$this->cookie($next));
            }
            $user=$this->user($claims['sub']); if (!$user) Identity::unauthorized();
            $next=Skif4erOidc::random();
            $this->identity->sql("INSERT INTO skif4er_login_flows(browser_hash,subject,user_id,expires_at) VALUES (:browser,:subject,:user,clock_timestamp()+interval '5 minutes')",['browser'=>hash('sha256',$next),'subject'=>$claims['sub'],'user'=>$user['id']]);
            return $this->redirect('/admin/?skif4er='.($user['two_factor_enabled']?'otp':'continue'),$this->cookie($next));
        } catch (\Throwable $e) {
            // Never expose code, tokens, state, provider error descriptions or internal exceptions.
            (new AuditLogger($this->pdo))->record('auth.skif4er_failed',['request_id'=>$r->requestId],null,null,'failure','anonymous');
            return $this->redirect('/admin/?skif4er='.($link?'link_failed#settings':'failed'));
        }
    }
    private function user(string $subject): ?array {
        $u=$this->identity->sql('SELECT u.id,u.email,u.role,u.blocked_at,u.two_factor_enabled,u.created_at,u.updated_at,u.display_name,u.last_login_at FROM skif4er_identities i JOIN users u ON u.id=i.user_id WHERE i.subject=:subject AND u.blocked_at IS NULL',['subject'=>$subject])->fetch();
        if (!$u) return null; $u['two_factor_enabled']=PostgresBoolean::required($u['two_factor_enabled']); return $u;
    }
    private function finish(Request $r): Response {
        $this->identity->origin($r); if ($r->header('Origin')!==$this->origin) Identity::forbidden(); $this->limit($r);
        // Same-origin JSON is mandatory; a cross-site form cannot finish an external login.
        if (!preg_match('~^application/json(?:\s*;.*)?$~i',$r->header('Content-Type','')??'')) throw new HttpException(415,'unsupported_media_type','JSON required.');
        $d=$r->json(); $otp=$d['otp']??null;
        if (array_diff(array_keys($d),['otp'])||($otp!==null&&(!is_string($otp)||!preg_match('/^(?:[0-9]{6}|[a-f0-9]{32})$/D',$otp)))) throw new HttpException(422,'invalid_payload','Invalid verification code.');
        $browser=$this->browser($r);
        $result=(new TransactionManager($this->pdo))->run(function() use($r,$browser,$otp) {
            $this->identity->lock();
            $f=$this->identity->sql('SELECT subject,user_id,attempts FROM skif4er_login_flows WHERE browser_hash=:browser AND subject IS NOT NULL AND link_session_id IS NULL AND expires_at>clock_timestamp() AND attempts<5 FOR UPDATE',['browser'=>$browser])->fetch();
            if (!$f) return null;
            $u=$this->user($f['subject']);
            if (!$u||$u['id']!==$f['user_id']) { $this->identity->sql('DELETE FROM skif4er_login_flows WHERE browser_hash=:browser',['browser'=>$browser]); return null; }
            if ($u['two_factor_enabled']&&$otp===null) return ['needs_otp'=>true];
            if ($u['two_factor_enabled']&&!$this->identity->totp()->verify($u['id'],$otp)) {
                $this->identity->sql('UPDATE skif4er_login_flows SET attempts=attempts+1 WHERE browser_hash=:browser',['browser'=>$browser]);
                (new AuditLogger($this->pdo))->record('auth.skif4er_failed',['request_id'=>$r->requestId],$u['id'],null,'failure'); return null;
            }
            $this->identity->sql('DELETE FROM skif4er_login_flows WHERE browser_hash=:browser',['browser'=>$browser]);
            if (preg_match('/(?:^|;\s*)cajeerlogs_session=([a-f0-9]{64})(?:;|$)/D',$r->header('Cookie','')??'',$m)) $this->identity->sql('DELETE FROM auth_sessions WHERE token_hash=:hash',['hash'=>hash('sha256',$m[1])]);
            $u['last_login_at']=$this->identity->sql('UPDATE users SET last_login_at=now() WHERE id=:id RETURNING last_login_at',['id'=>$u['id']])->fetchColumn();
            // External authentication is not proof of the local password for sensitive operations.
            $result=$this->identity->issueSession($u,$r,false,'skif4er');
            (new AuditLogger($this->pdo))->record('auth.skif4er_login',['request_id'=>$r->requestId],$u['id']); return $result;
        },maxAttempts:3);
        if ($result===null) Identity::unauthorized();
        if (isset($result['needs_otp'])) throw new HttpException(401,'second_factor_required','Enter a TOTP or recovery code.');
        $cookie=$result['cookie']; unset($result['cookie']); return new JsonResponse($result,200,['Set-Cookie'=>$cookie,'Cache-Control'=>'no-store']);
    }
    /** Session-only management, independent of provider availability. Never accept API tokens. */
    private function principal(Request $r): array {
        $credential=Identity::credentials($r);
        if ($credential['kind']!=='session') Identity::forbidden();
        if ($r->method==='POST' && ($r->header('Origin')!==$this->origin || !preg_match('~^application/json(?:\s*;.*)?$~i',$r->header('Content-Type','')??''))) Identity::forbidden();
        return $this->identity->authenticate($r,$credential);
    }
    private function account(Request $r): Response {
        $p=$this->principal($r);
        $linked=$this->identity->sql('SELECT created_at FROM skif4er_identities WHERE user_id=:id',['id'=>$p['id']])->fetchColumn();
        $pending=null;
        try { $browser=$this->browser($r); } catch (HttpException) { $browser=null; }
        if ($browser!==null && $this->enabled) $pending=$this->identity->sql('SELECT verified_email FROM skif4er_login_flows WHERE browser_hash=:browser AND link_session_id=:session AND user_id=:user AND subject IS NOT NULL AND expires_at>clock_timestamp()',['browser'=>$browser,'session'=>$p['session_id'],'user'=>$p['id']])->fetchColumn()?:null;
        return new JsonResponse(['enabled'=>$this->enabled,'linked'=>$linked!==false,'linked_at'=>$linked===false?null:$linked,'pending_email'=>$pending,'auth_method'=>$p['auth_method']],200,['Cache-Control'=>'no-store']);
    }
    /** Password + current local factor are mandatory even when generic freshness is disabled. */
    private function confirmed(Request $r,array $p): void {
        $d=$r->json(); $password=$d['password']??null; $otp=$d['otp']??null;
        if (!str_starts_with(ltrim($r->body()),'{')||array_diff(array_keys($d),['password','otp'])||!is_string($password)||$password===''||strlen($password)>1024||($otp!==null&&(!is_string($otp)||!preg_match('/^(?:[0-9]{6}|[a-f0-9]{32})$/D',$otp)))) throw new HttpException(422,'invalid_payload','Invalid confirmation.');
        $user=$this->identity->sql('SELECT password_hash,two_factor_enabled FROM users WHERE id=:id AND blocked_at IS NULL',['id'=>$p['id']])->fetch();
        if (!$user||!password_verify($password,$user['password_hash'])) throw new HttpException(403,'invalid_password','Current password is incorrect.');
        if (PostgresBoolean::required($user['two_factor_enabled'])&&!$this->identity->totp()->verify($p['id'],$otp)) throw new HttpException(403,'invalid_otp','Invalid second factor.');
    }
    private function management(Request $r,\Closure $operation): Response {
        $p=$this->principal($r);
        (new RateLimiter($this->pdo))->enforce('skif4er-manage:'.$p['id'],10,300);
        if (!str_ends_with($r->path,'/link/finish')) (new RateLimiter($this->pdo))->enforce('password:'.$p['id'],5,300);
        return (new TransactionManager($this->pdo))->run(function() use($r,$operation) {
            $this->identity->lock();
            $p=$this->principal($r); // Revalidate under the same lock as local identity mutations.
            return $operation($p);
        },maxAttempts:3);
    }
    private function link(Request $r): Response {
        // Validate authorization before provider I/O; do not hold the identity lock on network requests.
        $this->principal($r); $this->limit($r); $d=$this->oidc->discover();
        return $this->management($r,function(array $p) use($r,$d) {
            $this->confirmed($r,$p);
            if ($this->identity->sql('SELECT 1 FROM skif4er_identities WHERE user_id=:id',['id'=>$p['id']])->fetchColumn()) throw new HttpException(409,'conflict','Unlink the existing identity first.');
            $browser=Skif4erOidc::random(); $state=Skif4erOidc::random(); $nonce=Skif4erOidc::random(); $verifier=Skif4erOidc::random();
            $this->identity->sql('DELETE FROM skif4er_login_flows WHERE link_session_id=:session',['session'=>$p['session_id']]);
            $this->identity->sql("INSERT INTO skif4er_login_flows(browser_hash,state_hash,nonce,verifier,link_session_id,expires_at) VALUES (:browser,:state,:nonce,:verifier,:session,clock_timestamp()+interval '5 minutes')",['browser'=>hash('sha256',$browser),'state'=>hash('sha256',$state),'nonce'=>$nonce,'verifier'=>$verifier,'session'=>$p['session_id']]);
            return new JsonResponse(['authorization_url'=>$this->oidc->authorize($d,$state,$nonce,$verifier)],200,['Set-Cookie'=>$this->cookie($browser),'Cache-Control'=>'no-store']);
        });
    }
    private function linkFinish(Request $r): Response {
        if (!str_starts_with(ltrim($r->body()),'{')||$r->json()!==[]) throw new HttpException(422,'invalid_payload','Empty object required.');
        return $this->management($r,function(array $p) use($r) {
            $flow=$this->identity->sql('DELETE FROM skif4er_login_flows WHERE browser_hash=:browser AND link_session_id=:session AND user_id=:user AND subject IS NOT NULL AND expires_at>clock_timestamp() RETURNING subject',['browser'=>$this->browser($r),'session'=>$p['session_id'],'user'=>$p['id']])->fetch();
            if (!$flow) throw new HttpException(409,'conflict','Link confirmation expired or belongs to another session.');
            $insert=$this->identity->sql('INSERT INTO skif4er_identities(subject,user_id) VALUES (:subject,:user) ON CONFLICT DO NOTHING RETURNING user_id',['subject'=>$flow['subject'],'user'=>$p['id']])->fetchColumn();
            // Return a conflict after committing the one-time flow consumption.
            if ($insert===false) return new JsonResponse(['error'=>'conflict','message'=>'Identity or account is already linked.'],409,['Cache-Control'=>'no-store']);
            (new AuditLogger($this->pdo))->record('auth.skif4er_linked',['request_id'=>$r->requestId],$p['id']);
            return new JsonResponse(['linked'=>true],200,['Cache-Control'=>'no-store']);
        });
    }
    private function unlink(Request $r): Response { return $this->revokeExternal($r,true); }
    private function revoke(Request $r): Response { return $this->revokeExternal($r,false); }
    private function revokeExternal(Request $r,bool $unlink): Response {
        return $this->management($r,function(array $p) use($r,$unlink) {
            $this->confirmed($r,$p);
            if ($unlink) $this->identity->sql('DELETE FROM skif4er_identities WHERE user_id=:id',['id'=>$p['id']]);
            $this->identity->sql('DELETE FROM skif4er_login_flows WHERE user_id=:user OR link_session_id IN (SELECT id FROM auth_sessions WHERE user_id=:owner)',['user'=>$p['id'],'owner'=>$p['id']]);
            $count=$this->identity->sql("DELETE FROM auth_sessions WHERE user_id=:id AND auth_method='skif4er'",['id'=>$p['id']])->rowCount();
            (new AuditLogger($this->pdo))->record($unlink?'auth.skif4er_unlinked':'auth.skif4er_sessions_revoked',['request_id'=>$r->requestId,'sessions_revoked'=>$count],$p['id']);
            $ended=$p['auth_method']==='skif4er';
            return new JsonResponse(['sessions_revoked'=>$count,'current_session_revoked'=>$ended],200,['Cache-Control'=>'no-store']+($ended?['Set-Cookie'=>$this->identity->cookie('',true)]:[]));
        });
    }
}
