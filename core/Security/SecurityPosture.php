<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Config\ConfigRepository;
use PDO;

/** Read-only production security audit. Reports only named controls and aggregate counts. */
final class SecurityPosture
{
    /** All production controls are evaluated against effective runtime configuration. */
    public static function configuration(ConfigRepository $config): array
    {
        $prod=$config->get('app.env')==='production';
        $totp=$config->get('security.totp_key','');
        $databaseHost=(string)$config->get('database.host','');
        $localDb=in_array($databaseHost,['localhost','127.0.0.1','::1'],true) || str_starts_with($databaseHost,'/');
        return [
            'https_origin'=>!$prod || parse_url((string)$config->get('app.url',''),PHP_URL_SCHEME)==='https',
            'debug_disabled'=>!$prod || $config->get('app.debug')===false,
            'admin_totp_enforced'=>!$prod || $config->get('security.require_admin_totp')===true,
            'totp_encryption_key'=>!$prod || TwoFactorManager::keyValid(is_string($totp)?$totp:''),
            'idle_session_timeout'=>!$prod || (int)$config->get('security.session_idle_seconds',0)>0,
            'sensitive_action_reauthentication'=>!$prod || (int)$config->get('security.reauth_seconds',0)>0,
            'authenticated_metrics'=>!$prod || $config->get('observability.prometheus_enabled')!==true ||
                (is_string($config->get('operations.metrics_token')) && strlen($config->get('operations.metrics_token'))>=32),
            'remote_database_tls'=>!$prod || $localDb || ($config->get('database.require_tls')===true &&
                $config->get('database.sslmode')==='verify-full' && is_file((string)$config->get('database.sslrootcert',''))),
        ];
    }

    /** Count cross-scope assignments and unprotected users without exposing PII. */
    public static function database(PDO $pdo): array
    {
        $users=$pdo->query(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE u.role='administrator' AND u.blocked_at IS NULL AND NOT u.two_factor_enabled) AS admins_without_totp,
                count(*) FILTER (WHERE u.two_factor_enabled AND (t.user_id IS NULL OR t.pending_session IS NOT NULL)) AS totp_state_mismatch,
                count(*) FILTER (WHERE NOT u.two_factor_enabled AND t.user_id IS NOT NULL AND t.pending_session IS NULL) AS orphan_totp,
                count(*) FILTER (WHERE u.rbac_role_id IS NOT NULL AND (gr.id IS NULL OR gr.scope<>'global')) AS global_role_scope_mismatch
            FROM users u
            LEFT JOIN user_totp t ON t.user_id=u.id
            LEFT JOIN rbac_roles gr ON gr.id=u.rbac_role_id
            SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($users)) throw new \RuntimeException('Identity security query returned no data.');
        $assignments=$pdo->query(<<<'SQL'
            SELECT count(*) AS mismatch FROM project_members m
            LEFT JOIN rbac_roles r ON r.id=m.rbac_role_id
            WHERE m.rbac_role_id IS NOT NULL AND (r.id IS NULL OR r.scope<>'project')
            SQL)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($assignments)) throw new \RuntimeException('Project role security query returned no data.');
        $counts=[];
        foreach (['admins_without_totp','totp_state_mismatch','orphan_totp','global_role_scope_mismatch'] as $key) {
            if (!isset($users[$key]) || filter_var((string)$users[$key],FILTER_VALIDATE_INT)===false || (int)$users[$key]<0) throw new \RuntimeException('Invalid security aggregation.');
            $counts[$key]=(int)$users[$key];
        }
        if (!isset($assignments['mismatch']) || filter_var((string)$assignments['mismatch'],FILTER_VALIDATE_INT)===false || (int)$assignments['mismatch']<0) throw new \RuntimeException('Invalid scope aggregation.');
        $counts['project_role_scope_mismatch']=(int)$assignments['mismatch'];
        $counts['invalid_role_definitions']=0;
        $last=null; $batches=0;
        do {
            if (++$batches>100) throw new \RuntimeException('RBAC audit row limit exceeded.');
            $stmt=$pdo->prepare($last===null
                ? 'SELECT id,scope,permissions FROM rbac_roles ORDER BY id LIMIT 250'
                : 'SELECT id,scope,permissions FROM rbac_roles WHERE id>CAST(:after AS uuid) ORDER BY id LIMIT 250');
            $stmt->execute($last===null?[]:['after'=>$last]);
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows)>250) throw new \RuntimeException('Invalid RBAC result batch.');
            foreach ($rows as $row) {
                if (!is_string($row['id']??null) || $last!==null && strcmp($row['id'],$last)<=0) throw new \RuntimeException('Invalid RBAC pagination.');
                $last=$row['id'];
                try {
                    if (!in_array($row['scope']??null,['global','project'],true)) throw new \InvalidArgumentException();
                    $permissions=json_decode((string)($row['permissions']??''),true,16,JSON_THROW_ON_ERROR);
                    Rbac::validate($permissions);
                } catch (\Throwable) { $counts['invalid_role_definitions']++; }
            }
        } while (count($rows)===250);
        return $counts;
    }

    public static function evaluate(ConfigRepository $config, ?PDO $pdo=null, bool $probeTls=false, bool $verifyTotpSecrets=false): array
    {
        $checks=self::configuration($config);
        $counts=null;
        try {
            if ($pdo===null) throw new \RuntimeException('Database unavailable.');
            $counts=self::database($pdo);
            foreach ($counts as $name=>$value) {
                // Administrators must have 2FA when policy enforces it; others always fail closed.
                if ($name==='admins_without_totp' && $config->get('security.require_admin_totp')!==true) continue;
                $checks[$name]=$value===0;
            }
            if ($verifyTotpSecrets) {
                try {
                    $totp=new TwoFactorManager($pdo,(string)$config->get('security.totp_key',''),false,
                        (string)$config->get('security.totp_active_key',''),(string)$config->get('security.totp_previous_key',''));
                    $verified=$totp->verifyKeys();
                    $checks['totp_secret_decryption']=$verified['verified']>=0;
                    $checks['totp_key_rotation_complete']=$verified['remaining']===0;
                    $counts['totp_secrets_verified']=$verified['verified'];
                    $counts['totp_key_rotation_remaining']=$verified['remaining'];
                } catch (\Throwable) {
                    $checks['totp_secret_decryption']=false;
                }
            }
            $checks['identity_database_available']=true;
        } catch (\Throwable) { $checks['identity_database_available']=false; }
        if ($probeTls) {
            try { $checks['live_tls_certificate']=TlsProbe::check((string)$config->get('app.url'))['status']==='ok'; }
            catch (\Throwable) { $checks['live_tls_certificate']=false; }
        }
        return ['status'=>in_array(false,$checks,true)?'failed':'ok','checks'=>$checks,'counts'=>$counts];
    }
}
