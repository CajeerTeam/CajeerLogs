<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use Cajeer\Logs\Kernel\Application;
use PDO;

final class DatabaseStatus
{
    public function __construct(private readonly Application $app) {}

    public function check(bool $requireMigrations = true): array
    {
        try {
            $pdo = $this->app->container()->get(PDO::class);
            $factory = $this->app->container()->get(ConnectionFactory::class);
            $schema = $factory->schema();
            RestoreFence::assertAvailable($pdo);
            $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
            if ($pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'off' || $pdo->query('SELECT pg_is_in_recovery()')->fetchColumn() === true) {
                return ['status' => 'down', 'server_version' => $version, 'error' => 'Database session is read-only.'];
            }
            if ($pdo->query('SHOW fsync')->fetchColumn() !== 'on') { return ['status'=>'down','server_version'=>$version,'error'=>'PostgreSQL fsync must be enabled for durable ingest.']; }
            $migrationPdo = !$requireMigrations && PHP_SAPI==='cli' && (getenv('DB_MIGRATION_USERNAME')!==false || getenv('DB_MIGRATION_PASSWORD')!==false) ? $factory->make('migration') : $pdo;
            $priv = $migrationPdo->prepare("SELECT pg_catalog.has_schema_privilege(current_user, CAST(:schema AS text), 'CREATE')");
            $priv->execute(['schema' => $schema]);
            $canMigrate = (bool) $priv->fetchColumn();
            if (!$requireMigrations) { return ['status' => $canMigrate ? 'ok' : 'down', 'server_version' => $version, 'schema_create' => $canMigrate, 'separate_migration_role' => $migrationPdo !== $pdo]; }
            $status = $this->app->container()->get(MigrationRunner::class)->status();
            if ($status['pending'] !== [] || $status['integrity'] !== 'verified') { return ['status' => 'down', 'server_version' => $version, 'migrations' => $status]; }
            // Check every expected relation and its read/write privileges in one round trip.
            $relations = $pdo->prepare(<<<'SQL'
                WITH expected(name) AS (VALUES ('users'), ('projects'), ('sources'), ('api_tokens'), ('service_accounts'),
                    ('project_members'), ('auth_sessions'), ('auth_rate_limits'), ('ingest_receipts'), ('ingest_nonces'), ('ingest_signing'), ('log_events'), ('audit_log'), ('jobs'), ('settings'), ('webhooks'), ('extensions'), ('integration_webhooks'), ('webhook_deliveries'), ('webhook_attempts'), ('job_attempts'), ('scheduler_runs'), ('user_totp'), ('user_recovery_codes')),
                checked AS (
                    SELECT name, pg_catalog.to_regclass(pg_catalog.format('%I.%I', CAST(:schema AS text), name))::oid AS relation
                    FROM expected
                )
                SELECT name FROM checked WHERE relation IS NULL
                    OR NOT pg_catalog.has_table_privilege(current_user, relation, 'SELECT')
                    OR NOT pg_catalog.has_table_privilege(current_user, relation, 'INSERT')
                    OR (name<>'audit_log' AND NOT pg_catalog.has_table_privilege(current_user, relation, 'UPDATE'))
                    OR (name IN ('project_members','auth_sessions','auth_rate_limits','ingest_receipts','ingest_nonces','jobs','job_attempts','log_events','webhook_deliveries','webhook_attempts','user_totp','user_recovery_codes') AND NOT pg_catalog.has_table_privilege(current_user, relation, 'DELETE'))
                ORDER BY name
                SQL);
            $relations->execute(['schema' => $schema]);
            $missing = $relations->fetchAll(PDO::FETCH_COLUMN);
            if ($missing !== []) {
                return ['status' => 'down', 'server_version' => $version, 'error' => 'Missing or inaccessible database tables.', 'tables' => $missing];
            }
            // Async ingest uses jobs.id's sequence; table grants alone do not authorize nextval().
            $sequence = $pdo->prepare("SELECT pg_catalog.has_sequence_privilege(current_user, pg_catalog.pg_get_serial_sequence(pg_catalog.format('%I.jobs',CAST(:schema AS text)),'id'), 'USAGE')");
            $sequence->execute(['schema'=>$schema]);
            if ($sequence->fetchColumn() !== true) { return ['status'=>'down','server_version'=>$version,'error'=>'Runtime role needs USAGE on the jobs ID sequence.']; }
            $totpKey = $this->app->config()->get('security.totp_key');
            if ($this->app->config()->get('app.env') === 'production') {
                if (!$pdo->query("SELECT EXISTS(SELECT 1 FROM users WHERE role='administrator' AND blocked_at IS NULL)")->fetchColumn()) {
                    return ['status'=>'down','server_version'=>$version,'error'=>'Installation has no active administrator.'];
                }
                if (($this->app->config()->get('security.require_admin_totp') || \Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->requiresEnrollmentPolicy()) && !\Cajeer\Logs\Security\TwoFactorManager::keyValid($totpKey)) {
                    return ['status'=>'down','server_version'=>$version,'error'=>'Required TOTP encryption key is unavailable.'];
                }
            }
            if ($pdo->query('SELECT EXISTS(SELECT 1 FROM users u LEFT JOIN user_totp t ON t.user_id=u.id AND t.pending_session IS NULL WHERE u.two_factor_enabled AND t.user_id IS NULL)')->fetchColumn()) {
                return ['status'=>'down','server_version'=>$version,'error'=>'An enrolled user has no active TOTP secret.'];
            }
            // Check at least one enrollment for each stored key version, including pending enrollments.
            $enrolled = $pdo->query("SELECT DISTINCT ON (CASE WHEN secret_ciphertext LIKE 'v2:%' THEN split_part(secret_ciphertext,':',2) ELSE '' END) user_id AS id,secret_ciphertext FROM user_totp ORDER BY CASE WHEN secret_ciphertext LIKE 'v2:%' THEN split_part(secret_ciphertext,':',2) ELSE '' END,user_id")->fetchAll();
            foreach ($enrolled as $enrollment) {
                // A syntactically valid but wrong key previously passed readiness after restore/rotation.
                try { \Cajeer\Logs\Security\TwoFactorManager::fromConfig($pdo,$this->app->config())->open($enrollment['id'],$enrollment['secret_ciphertext']); }
                catch (\Throwable) { return ['status'=>'down','server_version'=>$version,'error'=>'TOTP encryption key cannot decrypt enrolled user secret.']; }
            }
            $signing = $pdo->query("SELECT s.token_id,s.current_key_id,s.current_ciphertext FROM ingest_signing s JOIN api_tokens t ON t.id=s.token_id WHERE s.current_key_id IS NOT NULL AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at>clock_timestamp()) ORDER BY s.token_id LIMIT 1")->fetch();
            if ($signing) {
                try { (new \Cajeer\Logs\Security\IngestSigning($pdo,$totpKey))->open($signing['token_id'],$signing['current_key_id'],$signing['current_ciphertext']); }
                catch (\Throwable) { return ['status'=>'down','server_version'=>$version,'error'=>'Signing encryption key cannot decrypt active signing secret.']; }
            }
            $session=$pdo->query("SELECT current_setting('application_name') AS application_name, current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout, current_setting('idle_in_transaction_session_timeout') AS idle_transaction_timeout, COALESCE((SELECT ssl FROM pg_catalog.pg_stat_ssl WHERE pid=pg_backend_pid()),false) AS tls")->fetch();
            return ['status' => 'ok', 'server_version' => $version, 'migrations' => $status, 'session'=>$session, 'connection'=>$this->app->container()->get(ConnectionManager::class)->metrics()];
        } catch (\Throwable $error) {
            return ['status' => 'down', 'error' => $error instanceof DatabaseException ? $error->getMessage() : 'Database query failed.', 'category'=>DatabaseFailure::category($error)];
        }
    }
}
