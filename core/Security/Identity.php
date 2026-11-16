<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Database\TransactionManager;

use PDO;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Database\Repository\TokenRepository;

/** Authenticated principals are request-local; no raw credentials leave this service. */
final class Identity
{
    private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$MTIzNDU2Nzg5MDEyMzQ1Ng$Qx3xdVf8W+33jD9M2Y2I8YtRVfPivgEht0+CNzR3GLU';
    public function __construct(private readonly PDO $pdo, private readonly bool $secureCookie, private readonly string $origin, private readonly int $ttl = 28800, private readonly string $totpKey = '', private readonly bool $requireAdminTotp = false, private readonly IdentityPolicy $policy = new IdentityPolicy(), private readonly string $totpActiveKey = '', private readonly string $totpPreviousKey = '') {}

    public static function fromConfig(PDO $pdo,bool $secure,string $origin,\Cajeer\Logs\Config\ConfigRepository $config): self {
        return new self($pdo,$secure,$origin,$config->get('security.session_ttl_seconds'),$config->get('security.totp_key'),$config->get('security.require_admin_totp'),IdentityPolicy::fromConfig($config),$config->get('security.totp_active_key',''),$config->get('security.totp_previous_key',''));
    }
    public function policy(): IdentityPolicy { return $this->policy; }
    public function totp(): TwoFactorManager { return new TwoFactorManager($this->pdo,$this->totpKey,$this->requireAdminTotp,$this->totpActiveKey,$this->totpPreviousKey); }

    public static function credentials(Request $r): array
    {
        $auth = $r->header('Authorization');
        $cookie = $r->header('Cookie','') ?? '';
        $sessions = [];
        foreach (explode(';',$cookie) as $part) {
            $pair = explode('=',trim($part),2);
            if ($pair[0] === 'cajeerlogs_session') { $sessions[] = $pair[1] ?? ''; }
        }
        if ($auth !== null) {
            if ($sessions !== [] || !preg_match('/^Bearer (cl_[a-f0-9]{64})$/iD',$auth,$m)) { self::unauthorized(); }
            return ['kind'=>'token','secret'=>$m[1]];
        }
        if (count($sessions) !== 1 || !preg_match('/^[a-f0-9]{64}$/D',$sessions[0])) { self::unauthorized(); }
        return ['kind'=>'session','secret'=>$sessions[0]];
    }
    public static function unauthorized(): never { throw new HttpException(401,'unauthorized','Authentication required or invalid credentials.', ['WWW-Authenticate'=>'Bearer realm="CajeerLogs"']); }
    public static function forbidden(): never { throw new HttpException(403,'forbidden','Access denied.'); }
    public function origin(Request $r): void
    {
        $origin = $r->header('Origin');
        if ($origin !== null && $origin !== $this->origin) { self::forbidden(); }
        if ($r->header('Sec-Fetch-Site') === 'cross-site') { self::forbidden(); }
    }
    public function cookie(string $value, bool $clear = false): string
    {
        return 'cajeerlogs_session=' . $value . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . ($clear ? '0' : $this->ttl) . ($this->secureCookie ? '; Secure' : '');
    }
    public function login(Request $r, string $email, string $password, ?string $otp = null): array
    {
        $this->origin($r);
        $rate = new RateLimiter($this->pdo);
        $rate->enforce('login-ip:' . IngestAuthorization::peerKey($r), 20, 300);
        $rate->enforce('login-email:' . $email, 10, 300);
        $users = new UserRepository($this->pdo);
        $user = $users->findByEmail($email);
        $valid = password_verify($password,$user['password_hash'] ?? self::DUMMY);
        if (!$valid || $user === null || $user['blocked_at'] !== null) {
            (new AuditLogger($this->pdo))->record('auth.login_failed',['request_id'=>$r->requestId],$user['id'] ?? null);
            self::unauthorized();
        }
        $newHash = password_needs_rehash($user['password_hash'],PASSWORD_ARGON2ID) ? password_hash($password,PASSWORD_ARGON2ID) : null;
        // Recheck after taking the write lock; expensive verification is outside the lock.
        $result=(new TransactionManager($this->pdo))->run(function () use ($users,$email,$user,$otp,$newHash,$r) {
            $this->lock();
            $current = $users->findByEmail($email);
            if ($current === null || $current['id'] !== $user['id'] || $current['password_hash'] !== $user['password_hash'] || $current['blocked_at'] !== null) { self::unauthorized(); }
            $user = $current;
            if ($user['two_factor_enabled'] && $otp === null) {
                throw new HttpException(401,'second_factor_required','Enter a TOTP or recovery code.', ['WWW-Authenticate'=>'Bearer realm="CajeerLogs"']);
            }
            if ($user['two_factor_enabled'] && !$this->totp()->verify($user['id'],$otp)) {
                (new AuditLogger($this->pdo))->record('auth.login_failed',['request_id'=>$r->requestId],$user['id']);
                return null;
            }
            if ($newHash !== null) {
                $this->sql('UPDATE users SET password_hash=:hash,updated_at=now() WHERE id=:id',['hash'=>$newHash,'id'=>$user['id']]);
            }
            // Fresh session on every login, never accept a client-selected ID.
            $old = $r->header('Cookie','') ?? '';
            if (preg_match('/(?:^|;\s*)cajeerlogs_session=([a-f0-9]{64})(?:;|$)/D',$old,$m)) {
                $this->sql('DELETE FROM auth_sessions WHERE token_hash=:hash',['hash'=>hash('sha256',$m[1])]);
            }
            $this->sql('UPDATE users SET last_login_at=now() WHERE id=:id',['id'=>$user['id']]);
            $user=$users->findByEmail($email);
            $result=$this->issueSession($user,$r);
            (new AuditLogger($this->pdo))->record('auth.login',['request_id'=>$r->requestId],$user['id']);
            return $result;
        }, maxAttempts: 3);
        if ($result===null) self::unauthorized();
        return $result;
    }
    public function totpRequired(array $user): bool {
        if ($this->requireAdminTotp && $user['role']==='administrator') return true;
        if (in_array($user['role'],$this->policy->roles,true)) return true;
        return $this->policy->permissions!==[] && $this->policy->requires($user['role'],$this->effectivePermissions(['kind'=>'session','id'=>$user['id']]));
    }
    public function enrollmentRequired(array $user): bool { return !$user['two_factor_enabled'] && $this->totpRequired($user); }
    public function securityState(array $user,?string $freshUntil=null): array { return ['totp_required'=>$this->totpRequired($user),'idle_seconds'=>$this->policy->idle,'reauth_seconds'=>$this->policy->fresh,'reauthenticated_until'=>$freshUntil]; }
    public function requireFresh(array $p): void {
        if ($this->policy->fresh===0) return;
        if ($p['kind']!=='session') self::forbidden();
        if (!$this->sql("SELECT 1 FROM auth_sessions WHERE token_hash=:hash AND reauthenticated_at>clock_timestamp()-interval '".$this->policy->fresh." seconds'",['hash'=>$p['hash']])->fetchColumn()) throw new HttpException(403,'reauthentication_required','Confirm your identity in account settings and retry the operation.');
    }
    public function issueSession(array $user, ?Request $request = null): array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Session creation requires transaction.'); }
        $secret=bin2hex(random_bytes(32)); $csrf=(new CsrfProtection())->token();
        $this->sql("INSERT INTO auth_sessions(token_hash,user_id,csrf_token,expires_at,user_agent,remote_address,reauthenticated_at) VALUES (:hash,:id,:csrf,now()+CAST(:ttl AS integer)*interval '1 second',:agent,:address,now())",['hash'=>hash('sha256',$secret),'id'=>$user['id'],'csrf'=>$csrf,'ttl'=>$this->ttl,'agent'=>self::sessionAgent($request),'address'=>$request !== null && filter_var($request->remoteAddress,FILTER_VALIDATE_IP) ? $request->remoteAddress : null]);
        return ['user'=>self::publicUser($user)+['permissions'=>$this->effectivePermissions(['kind'=>'session','id'=>$user['id']])],'csrf_token'=>$csrf,'cookie'=>$this->cookie($secret),'expires_in'=>$this->ttl,'enrollment_required'=>$this->enrollmentRequired($user),'security'=>$this->securityState($user,$this->policy->fresh>0?gmdate('Y-m-d\TH:i:s\Z',time()+$this->policy->fresh):null)];
    }
    private static function sessionAgent(?Request $request): ?string
    {
        $agent=$request?->header('User-Agent');
        if ($agent===null || !preg_match('//u',$agent)) return null;
        // Retain at most 512 Unicode characters, not a truncated UTF-8 byte sequence.
        return preg_match('/^.{0,512}/us',$agent,$m) ? $m[0] : null;
    }
    public function authenticate(Request $r, array $credential, bool $allowEnrollment = false, bool $chargeRate = true, bool $touchToken = true): array
    {
        if ($credential['kind'] === 'token') {
            $row = (new TokenRepository($this->pdo))->findActive($credential['secret'],$touchToken);
            if ($row === null) { self::unauthorized(); }
            // Legacy unscoped log tokens are fail-closed; system tokens must be global only.
            try { TokenRepository::scopes($row['scopes'],$row['project_id']); } catch (HttpException) { self::forbidden(); }
            if ($chargeRate && $row['requests_per_minute']!==null) (new RateLimiter($this->pdo))->enforce('credential:'.$row['id'],$row['requests_per_minute'],60);
            return ['kind'=>'token','id'=>$row['id'],'token'=>$row];
        }
        $hash = hash('sha256',$credential['secret']);
        $idle=$this->policy->idle;
        $condition=$idle>0?" AND s.last_seen_at>clock_timestamp()-interval '$idle seconds'":'';
        $row = $this->sql('SELECT u.id,u.email,u.role,u.blocked_at,u.two_factor_enabled,u.created_at,u.updated_at,u.display_name,u.last_login_at,s.csrf_token,s.id AS session_id,s.expires_at,s.reauthenticated_at FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=:hash AND s.expires_at>clock_timestamp() AND u.blocked_at IS NULL'.$condition,['hash'=>$hash])->fetch();
        if (!$row) { self::unauthorized(); }
        if (!$allowEnrollment && $this->enrollmentRequired($row)) { throw new HttpException(403,'totp_enrollment_required','Enroll in two-factor authentication first.'); }
        if (!in_array($r->method,['GET','HEAD','OPTIONS'],true)) {
            $this->origin($r);
            if (!(new CsrfProtection())->verify($row['csrf_token'],$r->header('X-CSRF-Token'))) { throw new HttpException(403,'csrf_failed','Invalid CSRF token.'); }
        }
        // Session polling alone must not keep an otherwise idle session alive.
        if ($idle>0 && !str_ends_with($r->path,'/auth/me') && !($r->method==='GET' && $r->header('X-Cajeer-Background')==='1')) {
            $updated=$this->sql("UPDATE auth_sessions SET last_seen_at=clock_timestamp() WHERE token_hash=:hash AND expires_at>clock_timestamp() AND last_seen_at>clock_timestamp()-interval '$idle seconds'",['hash'=>$hash]);
            if ($updated->rowCount()!==1) self::unauthorized();
        } elseif ($idle===0) $this->sql("UPDATE auth_sessions SET last_seen_at=now() WHERE token_hash=:hash AND last_seen_at<now()-interval '5 minutes'",['hash'=>$hash]);
        return ['kind'=>'session','id'=>$row['id'],'user'=>self::publicUser($row),'hash'=>$hash,'csrf'=>$row['csrf_token'],'session_id'=>$row['session_id'],'expires_at'=>$row['expires_at'],'reauthenticated_at'=>$row['reauthenticated_at']];
    }
    /** A project role can restrict, never elevate, the global role. Administrators retain global access. */
    public function projectRole(array $p,string $project): string
    {
        $role=$p['user']['role']; if ($role==='administrator') return $role;
        $row=$this->sql('SELECT project_role FROM project_members WHERE project_id=:project AND user_id=:user',['project'=>$project,'user'=>$p['id']])->fetch();
        if (!$row) self::forbidden();
        return $role==='viewer'||$row['project_role']==='viewer'?'viewer':$role;
    }
    public function permission(array $p, string $permission, ?string $project = null, ?string $source = null): void
    {
        if ($p['kind'] === 'token') {
            $t = $p['token'];
            try { $scopes=TokenRepository::scopes($t['scopes']??null,$t['project_id']??null); } catch (HttpException) { self::forbidden(); }
            if (!in_array($permission,$scopes,true) || $t['project_id'] !== $project || ($t['source_id'] !== null && $t['source_id'] !== $source)) { self::forbidden(); }
            return;
        }
        if (!in_array($permission,$this->effectivePermissions($p,$project),true)) self::forbidden();
    }
    /** Query current grants on each protected request; no cached permission snapshots in cookies. */
    public function effectivePermissions(array $p, ?string $project=null): array
    {
        if ($p['kind']!=='session') self::forbidden();
        // One statement: both role assignments and both definitions come from the same snapshot.
        $sql='SELECT u.role,u.rbac_role_id AS global_id,g.scope AS global_scope,g.permissions AS global_permissions';
        $params=['id'=>$p['id']];
        if ($project!==null) {
            $sql.=',m.user_id AS member,m.project_role,m.rbac_role_id AS project_id,r.scope AS project_scope,r.permissions AS project_permissions';
            $params['project']=$project;
        }
        $sql.=' FROM users u LEFT JOIN rbac_roles g ON g.id=u.rbac_role_id';
        if ($project!==null) $sql.=' LEFT JOIN project_members m ON m.user_id=u.id AND m.project_id=:project LEFT JOIN rbac_roles r ON r.id=m.rbac_role_id';
        $row=$this->sql($sql.' WHERE u.id=:id AND u.blocked_at IS NULL',$params)->fetch();
        if (!$row) self::forbidden();
        $grants=Rbac::restrict(Rbac::permissions($row['role']),$row['global_id'],$row['global_scope'],$row['global_permissions'],'global');
        if ($project===null || $row['role']==='administrator') return $grants;
        if ($row['member']===null) self::forbidden();
        if ($row['project_role']!==null) $grants=array_values(array_intersect($grants,Rbac::permissions($row['project_role'])));
        return Rbac::restrict($grants,$row['project_id'],$row['project_scope'],$row['project_permissions'],'project');
    }
    public function lock(): void { $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:identity:' || current_schema(),0))")->closeCursor(); }
    public function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public static function publicUser(array $u): array { return array_intersect_key($u,array_flip(['id','email','role','blocked_at','two_factor_enabled','created_at','updated_at','display_name','last_login_at'])); }
}
