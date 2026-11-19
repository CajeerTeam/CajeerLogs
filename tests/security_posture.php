<?php
declare(strict_types=1);

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Security\SecurityPosture;

$app=require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function assertPosture(bool $yes,string $name): void { global $n; if (!$yes) throw new RuntimeException('FAIL '.$name); $n++; echo "PASS $name\n"; }
$c=new ConfigRepository([
 'app'=>['env'=>'production','url'=>'https://logs.example.test','debug'=>false],
 'security'=>['require_admin_totp'=>true,'totp_key'=>bin2hex(random_bytes(32)),'session_idle_seconds'=>1800,'reauth_seconds'=>300],
 'observability'=>['prometheus_enabled'=>true], 'operations'=>['metrics_token'=>bin2hex(random_bytes(32))],
 'database'=>['host'=>'127.0.0.1','require_tls'=>false,'sslmode'=>'disable']]);
assertPosture(!in_array(false,SecurityPosture::configuration($c),true),'secure production configuration accepted');
foreach ([
 ['app.url','http://logs.example.test','https_origin'],
 ['app.debug',true,'debug_disabled'],
 ['security.require_admin_totp',false,'admin_totp_enforced'],
 ['security.totp_key','change_me','totp_encryption_key'],
 ['security.session_idle_seconds',0,'idle_session_timeout'],
 ['security.reauth_seconds',0,'sensitive_action_reauthentication'],
 ['operations.metrics_token','','authenticated_metrics'],
 ['database.host','db.internal','remote_database_tls'],
] as [$key,$bad,$control]) {
 $previous=$c->get($key); $c->set($key,$bad);
 assertPosture(SecurityPosture::configuration($c)[$control]===false,'unsafe '.$control.' detected');
 $c->set($key,$previous);
}
$c->set('database.host','db.internal');$c->set('database.require_tls',true);$c->set('database.sslmode','verify-full');$c->set('database.sslrootcert',__FILE__);
assertPosture(SecurityPosture::configuration($c)['remote_database_tls'],'verified remote PostgreSQL TLS accepted');
$c->set('database.host','127.0.0.1');$c->set('database.require_tls',false);
$c->set('observability.prometheus_enabled',false);$c->set('operations.metrics_token','');
assertPosture(SecurityPosture::configuration($c)['authenticated_metrics'],'disabled metrics exempt from auth check');
$c->set('observability.prometheus_enabled',true);$c->set('operations.metrics_token',bin2hex(random_bytes(32)));

class PostureStatement extends PDOStatement {
 public function __construct(private array $rows) {}
 public function execute(?array $params=null): bool { return true; }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return array_shift($this->rows) ?: false; }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class PostureDb extends PDO {
 public function __construct(public array $users, public array $assignments, public array $roles=[], public bool $broken=false) {}
 public function query(string $query, ?int $fetchMode=null,mixed ...$args): PDOStatement|false {
  if ($this->broken) throw new RuntimeException('Database down: secret credentials not for report');
  return new PostureStatement([str_contains($query,'FROM users u')?$this->users:$this->assignments]);
 }
 public function prepare(string $query, array $options=[]): PDOStatement|false {
  if (str_contains($query,'FROM user_totp')) return new PostureStatement([['user_id'=>'11111111-1111-4111-8111-111111111111','secret_ciphertext'=>'invalid-ciphertext']]);
  return new PostureStatement($this->roles);
 }
}
$zero=['admins_without_totp'=>'0','totp_state_mismatch'=>'0','orphan_totp'=>'0','global_role_scope_mismatch'=>'0'];
$good=new PostureDb($zero,['mismatch'=>'0'],[['id'=>'00000000-0000-0000-0000-000000000001','scope'=>'project','permissions'=>'["projects:read","logs:read"]']]);
$counts=SecurityPosture::database($good);
assertPosture(count($counts)===6 && max($counts)===0,'stored user and RBAC integrity verified');
assertPosture(SecurityPosture::evaluate($c,$good)['status']==='ok','healthy config and database pass audit');
$bad=new PostureDb([...$zero,'totp_state_mismatch'=>'2'],['mismatch'=>'1'],[['id'=>'00000000-0000-0000-0000-000000000001','scope'=>'global','permissions'=>'["logs:write"]']]);
$report=SecurityPosture::evaluate($c,$bad);
assertPosture($report['status']==='failed' && $report['counts']['totp_state_mismatch']===2 && $report['counts']['invalid_role_definitions']===1 && $report['counts']['project_role_scope_mismatch']===1,'corrupt 2FA and RBAC state fail audit');
$without=new PostureDb([...$zero,'admins_without_totp'=>'1'],['mismatch'=>'0']);
assertPosture(SecurityPosture::evaluate($c,$without)['status']==='failed','admin without 2FA fails enforced policy');
$unverified=SecurityPosture::evaluate($c,$good,false,true);
assertPosture($unverified['status']==='failed' && $unverified['checks']['totp_secret_decryption']===false,'deep TOTP audit fails closed if ciphertext cannot be verified');
$unavailable=SecurityPosture::evaluate($c,new PostureDb($zero,['mismatch'=>0],[],true));
assertPosture($unavailable['status']==='failed' && $unavailable['checks']['identity_database_available']===false && !isset($unavailable['error']),'database failure closed without leaking credentials');
$c->set('app.env','test');
assertPosture(!in_array(false,SecurityPosture::configuration($c),true),'local test configuration not blocked by production policy');
assertPosture(SecurityPosture::evaluate($c,null)['status']==='failed','missing database cannot result in successful audit');
echo "Security posture: $n checks passed\n";
