<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Config\SecretFile;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Kernel\Application;

final class EnvironmentWriter
{
    public function __construct(private readonly Application $app) {}

    public function write(?callable $validate = null): void
    {
        $path = $this->app->environmentPath();
        SecureFile::assertDirectory(dirname($path));
        SecureFile::assertPrivate($path,true);
        $original = is_file($path) ? file_get_contents($path) : null;
        if ($original===false) throw new DatabaseException('Cannot read existing configuration.');
        $existing = is_file($path) ? Environment::load($path) : [];
        $values = is_file($path) ? $existing : Environment::load($this->app->basePath('.env.example'));
        if (!is_file($path)) { $values['APP_ENV'] = (string) $this->app->config()->get('app.env'); $values['APP_URL'] = (string) $this->app->config()->get('app.url'); $values['APP_DEBUG'] = 'false'; }
        // Preserve effective runtime settings when installing from environment-only configuration.
        foreach (['SECURITY_TRUSTED_PROXIES','DB_REQUIRE_TLS','SEARCH_TIMEOUT_MS','APP_NAME','APP_ENV','APP_DEBUG','APP_URL','APP_LOCALE','APP_TIMEZONE','APP_MAX_BODY_BYTES','API_PREFIX','API_RATE_LIMIT_PER_MINUTE','API_RATE_SEARCH_PER_MINUTE','API_RATE_ANALYTICS_PER_MINUTE','API_RATE_EXPORT_PER_MINUTE','API_RATE_ADMIN_WRITE_PER_MINUTE','API_RATE_BURST_PERCENT','PROMETHEUS_ENABLED','PROMETHEUS_PATH','MONITOR_BACKUP_ENABLED','OPENAPI_PATH','INGEST_REQUIRE_HMAC','INGEST_IDEMPOTENCY_SECONDS','INGEST_MAX_INFLIGHT','INGEST_BUDGET_MS','INGEST_MAX_EVENT_AGE_SECONDS','INGEST_MAX_EVENT_FUTURE_SECONDS','QUEUE_MAX_JOBS','QUEUE_MAX_ATTEMPTS','QUEUE_RETRY_BASE_SECONDS','QUEUE_RETRY_MAX_SECONDS','QUEUE_RETRY_JITTER_PERCENT','QUEUE_OUTAGE_BASE_MS','QUEUE_OUTAGE_MAX_MS','QUEUE_OUTAGE_MAX_FAILURES','QUEUE_WORKER_PROCESSES','QUEUE_WORKER_MAX_JOBS','QUEUE_WORKER_MAX_SECONDS','QUEUE_WORKER_MEMORY_MB','QUEUE_POLL_MIN_MS','QUEUE_POLL_MAX_MS','QUEUE_LEASE_SECONDS','QUEUE_CLAIM_TIMEOUT_MS','QUEUE_PROCESS_TIMEOUT_SECONDS','QUEUE_SHUTDOWN_GRACE_SECONDS','SECURITY_2FA_REQUIRED_FOR_ADMINS','SECURITY_2FA_REQUIRED_ROLES','SECURITY_2FA_REQUIRED_PERMISSIONS','SECURITY_SESSION_TTL_SECONDS','SECURITY_SESSION_IDLE_SECONDS','SECURITY_REAUTH_SECONDS','SECURITY_TOTP_ACTIVE_KEY','SECURITY_TOTP_PREVIOUS_KEY','SECURITY_TOTP_KEY','PROMETHEUS_TOKEN','BACKUP_DIR','BACKUP_OFFSITE_DIR','BACKUP_ENCRYPTION_KEY','BACKUP_TIMEOUT_SECONDS','BACKUP_MIN_FREE_BYTES','RETENTION_SCHEDULER_ENABLED','RETENTION_WINDOW_UTC','STORAGE_WARN_BYTES','RETENTION_BATCH_SIZE','RETENTION_TIMEOUT_MS','RETENTION_CYCLE_BATCHES','RETENTION_CYCLE_BUDGET_MS','RETENTION_PAUSE_MS','WEBHOOK_RETENTION_DAYS','QUEUE_RETENTION_FLOOR_SECONDS','BACKUP_INTERVAL_SECONDS','BACKUP_KEEP_LOCAL','BACKUP_KEEP_DAILY','BACKUP_KEEP_WEEKLY','BACKUP_KEEP_MONTHLY','BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE','PG_DUMP_BIN','PG_RESTORE_BIN'] as $key) {
            if (getenv($key) !== false || isset($_ENV[$key])) { $values[$key] = env_raw($key); }
        }
        $keys = ['DB_CONNECTION'=>'default','DB_HOST'=>'host','DB_PORT'=>'port','DB_DATABASE'=>'database','DB_USERNAME'=>'username','DB_PASSWORD'=>'password','DB_SCHEMA'=>'schema','DB_SSLMODE'=>'sslmode','DB_SSLROOTCERT'=>'sslrootcert','DB_CONNECT_TIMEOUT'=>'connect_timeout','DB_STATEMENT_TIMEOUT_MS'=>'statement_timeout_ms','DB_LOCK_TIMEOUT_MS'=>'lock_timeout_ms','DB_APPLICATION_NAME'=>'application_name','DB_CONNECT_ATTEMPTS'=>'connect_attempts','DB_CONNECT_RETRY_MS'=>'connect_retry_ms','DB_IDLE_TRANSACTION_TIMEOUT_MS'=>'idle_transaction_timeout_ms','DB_KEEPALIVES_IDLE'=>'keepalives_idle','DB_KEEPALIVES_INTERVAL'=>'keepalives_interval','DB_KEEPALIVES_COUNT'=>'keepalives_count','DB_TCP_USER_TIMEOUT_MS'=>'tcp_user_timeout_ms'];
        foreach ($keys as $env => $key) {
            $effective = (string) $this->app->config()->get('database.' . $key);
            if ((!in_array($existing['DB_PASSWORD'] ?? '', ['', 'change_me'], true) || !empty($existing['DB_PASSWORD_FILE'])) && in_array($env, ['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_SCHEMA'], true) && isset($existing[$env]) && $existing[$env] !== $effective) {
                throw new DatabaseException('Installer will not change an existing database target: ' . $env . '. Edit configuration explicitly.');
            }
            $values[$env] = $effective;
        }
        foreach (['SECURITY_HMAC_SECRET','SECURITY_TOKEN_PEPPER','SECURITY_PRIVACY_HASH_PEPPER','WEBHOOK_SIGNING_SECRET'] as $key) {
            $external = getenv($key);
            $value = SecretFile::raw($key,$existing,(string)($values[$key]??''));
            if (isset($existing[$key]) && strlen($existing[$key]) >= 32 && !str_starts_with($existing[$key], 'change_me') && $existing[$key] !== $value) { throw new DatabaseException('Installer will not rotate an existing secret: ' . $key); }
            $values[$key] = strlen($value) < 32 || str_starts_with($value, 'change_me') ? bin2hex(random_bytes(32)) : $value;
            if (($external !== false || isset($_ENV[$key]) || SecretFile::reference($key,$existing)!==null) && $value !== $values[$key]) { throw new DatabaseException('Remove placeholder external secret before installation: ' . $key); }
        }
        // Resolve runtime file references before validation, but never serialize their contents.
        $references=[];
        foreach (SecretFile::KEYS as $key) {
            $ref=SecretFile::reference($key,$existing);
            if ($ref!==null) {
                $resolved=SecretFile::raw($key,$existing);
                if ($key==='DB_PASSWORD' && $resolved!==$values[$key]) throw new DatabaseException('Database password file differs from effective installer configuration.');
                if (!empty($existing[$key.'_FILE']) && $existing[$key.'_FILE']!==$ref) throw new DatabaseException('Installer will not change an existing secret file reference: '.$key);
                $values[$key]=$resolved; $references[$key]=$ref;
            } elseif (!empty($existing[$key.'_FILE'])) {
                throw new DatabaseException('Installer will not remove an existing secret file reference: '.$key);
            }
        }
        // Preserve existing keys; never persist the administrator password.
        foreach (['SECURITY_TOTP_KEY','SECURITY_TOTP_ACTIVE_KEY','SECURITY_TOTP_PREVIOUS_KEY'] as $key) {
            if (!empty($existing[$key]) && $existing[$key]!==($values[$key]??'')) throw new DatabaseException('Installer will not rotate an existing TOTP key: '.$key);
        }
        foreach (['CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE','CAJEER_ADMIN_EMAIL','CAJEER_ADMIN_NEW_PASSWORD','CAJEER_ADMIN_NEW_PASSWORD_FILE','DB_MIGRATION_USERNAME','DB_MIGRATION_PASSWORD','DB_MIGRATION_PASSWORD_FILE'] as $key) { unset($values[$key]); }
        $totp = ($values['SECURITY_TOTP_KEY'] ?? '') ?: $values['SECURITY_HMAC_SECRET'];
        if (!\Cajeer\Logs\Security\TwoFactorManager::keyValid($totp)) { throw new DatabaseException('Invalid TOTP encryption key; configure SECURITY_TOTP_KEY as 64 random hexadecimal characters.'); }
        if ($validate !== null) { $validate($values); }
        foreach ($references as $key=>$ref) { unset($values[$key]); $values[$key.'_FILE']=$ref; }
        // No rewrite on an unchanged retry; preserve operator comments and original file identity.
        if ($values==$existing) return;
        $body = "# CajeerLogs configuration; generated by CLI installer. Keep private.\n";
        foreach ($values as $key => $value) {
            $body .= $key . '="' . strtr($value, ['\\'=>'\\\\','"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']) . "\"\n";
        }
        $temp = tempnam(dirname($path), '.env-install-');
        if ($temp === false) { throw new DatabaseException('Cannot create temporary environment file.'); }
        if (realpath(dirname($temp)) !== realpath(dirname($path))) { unlink($temp); throw new DatabaseException('Temporary environment file must be on the same filesystem.'); }
        try {
            if (!chmod($temp, 0600)) { throw new DatabaseException('Cannot protect temporary environment file.'); }
            $stream = fopen($temp, 'wb');
            if ($stream === false) { throw new DatabaseException('Cannot open temporary environment file.'); }
            try {
                if (fwrite($stream, $body) !== strlen($body) || !fflush($stream) || !fsync($stream)) { throw new DatabaseException('Cannot durably write environment file.'); }
            } finally { fclose($stream); }
            SecureFile::assertPrivate($path,true);
            if (($original===null ? file_exists($path) : !is_file($path) || file_get_contents($path)!==$original) || !rename($temp, $path)) { throw new DatabaseException('Cannot atomically write .env.'); }
        // Persist the rename itself, not only the file contents (Linux/POSIX).
            $directory=fopen(dirname($path),'r');
            if ($directory===false) throw new DatabaseException('Configuration published; cannot synchronize directory. Verify and retry.');
            try { if (!fsync($directory)) throw new DatabaseException('Configuration published; directory synchronization failed. Verify and retry.'); }
            finally { fclose($directory); }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }
}
