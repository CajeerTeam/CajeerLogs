<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Security\{SecurityPosture,TwoFactorManager};

if (getenv('CAJEER_TEST_DATABASE')!=='1' || getenv('APP_ENV')!=='test') throw new RuntimeException('Use disposable PostgreSQL test database only.');
$root=dirname(__DIR__);
$baseApp=require $root.'/core/bootstrap.php'; $baseApp->boot();
$base=$baseApp->container()->get(PDO::class);
$schema='cl_secposture_'.bin2hex(random_bytes(8));
$base->exec('CREATE SCHEMA "'.$schema.'"');
$checks=0;
function secCheck(bool $ok,string $label): void {global $checks;if (!$ok) throw new RuntimeException('FAIL '.$label); ++$checks;echo "PASS $label\n";}
try {
    $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');
    $cfg->set('security.require_admin_totp',true);$cfg->set('security.totp_key',bin2hex(random_bytes(32)));
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $stats=SecurityPosture::database($pdo);
    secCheck(max($stats)===0,'empty migrated PostgreSQL schema has safe identity state');
    $q=$pdo->prepare("INSERT INTO users(email,password_hash,role) VALUES (:email,:hash,:role) RETURNING id");
    $q->execute(['email'=>'audit-admin@example.test','hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_ARGON2ID),'role'=>'administrator']);
    $id=$q->fetchColumn();$q->closeCursor();
    secCheck(SecurityPosture::database($pdo)['admins_without_totp']===1,'unenrolled active admin discovered');
    $totp=new TwoFactorManager($pdo,$cfg->get('security.totp_key'));
    $pdo->prepare('UPDATE users SET two_factor_enabled=true WHERE id=:id')->execute(['id'=>$id]);
    secCheck(SecurityPosture::database($pdo)['totp_state_mismatch']===1,'invalid enabled TOTP flag discovered');
    $cipher=$totp->seal($id,random_bytes(20));
    $pdo->prepare('INSERT INTO user_totp(user_id,secret_ciphertext) VALUES (:id,:cipher)')->execute(['id'=>$id,'cipher'=>$cipher]);
    secCheck(max(SecurityPosture::database($pdo))===0,'valid encrypted TOTP state accepted');
    secCheck(SecurityPosture::evaluate($cfg,$pdo,false,true)['status']==='ok','real encrypted TOTP secret decrypts during deep audit');
    $pdo->prepare('UPDATE user_totp SET secret_ciphertext=:cipher WHERE user_id=:id')->execute(['id'=>$id,'cipher'=>'corrupt-ciphertext']);
    $broken=SecurityPosture::evaluate($cfg,$pdo,false,true);
    secCheck($broken['status']==='failed' && $broken['checks']['totp_secret_decryption']===false,'tampered TOTP ciphertext blocks security audit');
    $pdo->prepare('UPDATE user_totp SET secret_ciphertext=:cipher WHERE user_id=:id')->execute(['id'=>$id,'cipher'=>$cipher]);
    $pdo->exec("INSERT INTO rbac_roles(name,scope,permissions) VALUES ('Bad Role','project','[\"logs:write\"]'::jsonb)");
    secCheck(SecurityPosture::database($pdo)['invalid_role_definitions']===1,'malformed stored RBAC permissions discovered');
    $pdo->exec("DELETE FROM rbac_roles WHERE name='Bad Role'");
    $role=$pdo->query("INSERT INTO rbac_roles(name,scope,permissions) VALUES ('Project Role','project','[\"projects:read\",\"logs:read\"]'::jsonb) RETURNING id")->fetchColumn();
    $q->execute(['email'=>'audit-user@example.test','hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_ARGON2ID),'role'=>'operator']);$operator=$q->fetchColumn();$q->closeCursor();
    $pdo->prepare('UPDATE users SET rbac_role_id=:role WHERE id=:id')->execute(['role'=>$role,'id'=>$operator]);
    secCheck(SecurityPosture::database($pdo)['global_role_scope_mismatch']===1,'stored cross-scope role assignment discovered');
    secCheck(SecurityPosture::evaluate($cfg,$pdo)['status']==='failed','cross-scope grants fail audit');
    $pdo->prepare('UPDATE users SET rbac_role_id=NULL WHERE id=:id')->execute(['id'=>$operator]);
    secCheck(SecurityPosture::evaluate($cfg,$pdo)['status']==='ok','restored valid role state passes audit');
    echo "Security posture PostgreSQL: $checks checks passed\n";
} finally { $base->exec('DROP SCHEMA "'.$schema.'" CASCADE'); }
