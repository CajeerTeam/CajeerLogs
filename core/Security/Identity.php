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
    public function __construct(private readonly PDO $pdo, private readonly bool $secureCookie, private readonly string $origin, private readonly int $ttl = 28800, private readonly string $totpKey = '', private readonly bool $requireAdminTotp = false) {}

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
        $rate->enforce('login-ip:' . $r->remoteAddress, 20, 300);
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
            if ($user['two_factor_enabled'] && !(new TwoFactorManager($this->pdo,$this->totpKey))->verify($user['id'],$otp)) {
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
            $result=$this->issueSession($user);
            (new AuditLogger($this->pdo))->record('auth.login',['request_id'=>$r->requestId],$user['id']);
            return $result;
        }, maxAttempts: 3);
        if ($result===null) self::unauthorized();
        return $result;
    }
    public function enrollmentRequired(array $user): bool { return $this->requireAdminTotp && $user['role']==='administrator' && !$user['two_factor_enabled']; }
    public function issueSession(array $user): array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Session creation requires transaction.'); }
        $secret=bin2hex(random_bytes(32)); $csrf=(new CsrfProtection())->token();
        $this->sql("INSERT INTO auth_sessions(token_hash,user_id,csrf_token,expires_at) VALUES (:hash,:id,:csrf,now()+CAST(:ttl AS integer)*interval '1 second')",['hash'=>hash('sha256',$secret),'id'=>$user['id'],'csrf'=>$csrf,'ttl'=>$this->ttl]);
        return ['user'=>self::publicUser($user),'csrf_token'=>$csrf,'cookie'=>$this->cookie($secret),'expires_in'=>$this->ttl,'enrollment_required'=>$this->enrollmentRequired($user)];
    }
    public function authenticate(Request $r, array $credential, bool $allowEnrollment = false): array
    {
        if ($credential['kind'] === 'token') {
            $row = (new TokenRepository($this->pdo))->findActive($credential['secret']);
            if ($row === null) { self::unauthorized(); }
            // Legacy unscoped log tokens are fail-closed; system tokens must be global only.
            if ($row['project_id'] === null && $row['scopes'] !== ['system:read']) { self::forbidden(); }
            if ($row['project_id'] !== null && in_array('system:read',$row['scopes'],true)) { self::forbidden(); }
            return ['kind'=>'token','id'=>$row['id'],'token'=>$row];
        }
        $hash = hash('sha256',$credential['secret']);
        $row = $this->sql('SELECT u.id,u.email,u.role,u.blocked_at,u.two_factor_enabled,u.created_at,u.updated_at,s.csrf_token FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=:hash AND s.expires_at>now() AND u.blocked_at IS NULL',['hash'=>$hash])->fetch();
        if (!$row) { self::unauthorized(); }
        if (!$allowEnrollment && $this->enrollmentRequired($row)) { throw new HttpException(403,'totp_enrollment_required','Enroll in two-factor authentication first.'); }
        if (!in_array($r->method,['GET','HEAD','OPTIONS'],true)) {
            $this->origin($r);
            if (!(new CsrfProtection())->verify($row['csrf_token'],$r->header('X-CSRF-Token'))) { throw new HttpException(403,'csrf_failed','Invalid CSRF token.'); }
        }
        $this->sql("UPDATE auth_sessions SET last_seen_at=now() WHERE token_hash=:hash AND last_seen_at<now()-interval '5 minutes'",['hash'=>$hash]);
        return ['kind'=>'session','id'=>$row['id'],'user'=>self::publicUser($row),'hash'=>$hash,'csrf'=>$row['csrf_token']];
    }
    public function permission(array $p, string $permission, ?string $project = null, ?string $source = null): void
    {
        if ($p['kind'] === 'token') {
            $t = $p['token'];
            if (!in_array($permission,$t['scopes'],true) || $t['project_id'] !== $project || ($t['source_id'] !== null && $t['source_id'] !== $source)) { self::forbidden(); }
            return;
        }
        $role = $p['user']['role'];
        if (!(new Rbac())->roleAllows($role,$permission)) { self::forbidden(); }
        if ($project !== null && $role !== 'administrator' && !$this->sql('SELECT 1 FROM project_members WHERE project_id=:project AND user_id=:user',['project'=>$project,'user'=>$p['id']])->fetchColumn()) { self::forbidden(); }
    }
    public function lock(): void { $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:identity:' || current_schema(),0))")->closeCursor(); }
    public function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public static function publicUser(array $u): array { return array_intersect_key($u,array_flip(['id','email','role','blocked_at','two_factor_enabled','created_at','updated_at'])); }
}
