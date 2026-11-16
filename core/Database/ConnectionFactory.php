<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use Cajeer\Logs\Config\ConfigRepository;
use PDO;
use PDOException;

final class ConnectionFactory implements ConnectionFactoryInterface
{
    private int $attemptCount=0;
    private int $failedAttempts=0;

    /** Counts for this factory instance, not cross-process totals. */
    public function attempts(): array { return ['attempts'=>$this->attemptCount,'failed_attempts'=>$this->failedAttempts]; }

    public function __construct(private readonly ConfigRepository $config) {}

    public function schema(): string
    {
        $schema = $this->config->get('database.schema');
        if (!is_string($schema) || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) || str_starts_with($schema, 'pg_') || $schema === 'information_schema') {
            throw new DatabaseException('Invalid DB_SCHEMA (use a non-system lowercase PostgreSQL identifier).');
        }
        return $schema;
    }

    public function make(string $connection): PDO
    {
        if (!in_array($connection, ['pgsql','migration','migration-bootstrap'], true) || $this->config->get('database.default') !== 'pgsql') {
            throw new DatabaseException('Only PostgreSQL is supported.');
        }
        $schema = $this->schema();
        $host = $this->field('host');
        $database = $this->field('database');
        $username = $this->field('username');
        $password = $this->config->get('database.password');
        // Elevated credentials are injected into trusted CLI processes only, never runtime .env.
        if ($connection !== 'pgsql') {
            if (PHP_SAPI !== 'cli') { throw new DatabaseException('Migration connections require CLI.'); }
            $user = getenv('DB_MIGRATION_USERNAME');
            $secret = getenv('DB_MIGRATION_PASSWORD');
            $file=getenv('DB_MIGRATION_PASSWORD_FILE');
            if ($file!==false) {
                if ($secret!==false) throw new DatabaseException('Supply either DB_MIGRATION_PASSWORD or DB_MIGRATION_PASSWORD_FILE, not both.');
                $secret=\Cajeer\Logs\Config\SecretFile::read($file);
            }
            if ($connection === 'migration-bootstrap' && ($user === false || $secret === false)) { throw new DatabaseException('Schema preparation requires explicit migration credentials.'); }
            if ($user !== false || $secret !== false) {
                if ($user === false || $user === '' || $secret === false || $secret === '') { throw new DatabaseException('Set both DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD.'); }
                $username = $user; $password = $secret;
            }
        }
        if (!is_string($password) || $password === '' || $password === 'change_me' || str_contains($password, "\0")) {
            throw new DatabaseException('DB_PASSWORD must be a non-placeholder password.');
        }
        $port = $this->number('port', 1, 65535);
        $timeout = $this->number('connect_timeout', 1, 60);
        $statement = $this->number('statement_timeout_ms', 1, 600000);
        $lock = $this->number('lock_timeout_ms', 1, 600000);
        $idle = $this->number('idle_transaction_timeout_ms', 1000, 3600000);
        $attempts = $this->number('connect_attempts', 1, 5);
        $delay = $this->number('connect_retry_ms', 10, 5000);
        $keepIdle = $this->number('keepalives_idle', 1, 600);
        $keepInterval = $this->number('keepalives_interval', 1, 120);
        $keepCount = $this->number('keepalives_count', 1, 10);
        $tcpTimeout = $this->number('tcp_user_timeout_ms', 1000, 600000);
        $application = $this->field('application_name');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,48}$/D', $application)) { throw new DatabaseException('Invalid DB_APPLICATION_NAME.'); }
        if ($connection !== 'pgsql') { $application .= ':migration'; }
        $ssl = $this->field('sslmode');
        if (!in_array($ssl, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
            throw new DatabaseException('Invalid DB_SSLMODE.');
        }
        // Restrict DSN fields rather than interpolating arbitrary libpq connection options.
        if (!preg_match('~^[A-Za-z0-9_.:/-]+$~D', $host) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $database) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $username)) {
            throw new DatabaseException('Invalid DB_HOST, DB_DATABASE or DB_USERNAME.');
        }
        $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=$ssl;gssencmode=disable;connect_timeout=$timeout;application_name=$application;keepalives=1;keepalives_idle=$keepIdle;keepalives_interval=$keepInterval;keepalives_count=$keepCount;tcp_user_timeout=$tcpTimeout";
        $cert = $this->config->get('database.sslrootcert');
        if ($cert !== '') {
            if (!is_string($cert) || !preg_match('~^/[A-Za-z0-9_./-]+$~D', $cert) || !is_readable($cert)) { throw new DatabaseException('Invalid DB_SSLROOTCERT.'); }
            $dsn .= ';sslrootcert=' . $cert;
        }
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseException('Missing PHP extension pdo_pgsql.');
        }
        for ($attempt=1; ; $attempt++) {
          ++$this->attemptCount;
          try {
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_PERSISTENT => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false]);
            if ((int) $pdo->query('SHOW server_version_num')->fetchColumn() < 170000) { throw new DatabaseException('PostgreSQL 17 or newer is required.'); }
            $pdo->exec($connection === 'migration-bootstrap' ? 'SET search_path TO pg_catalog, pg_temp' : "SET search_path TO \"$schema\", pg_catalog, pg_temp");
            $pdo->exec("SET TIME ZONE 'UTC'; SET statement_timeout = $statement; SET lock_timeout = $lock; SET idle_in_transaction_session_timeout = $idle");
            $pdo->exec("SET client_encoding TO 'UTF8'");
            RestoreFence::assertAvailable($pdo);
            if ($connection === 'migration-bootstrap') { return $pdo; }
            $check = $pdo->prepare('SELECT pg_catalog.has_schema_privilege(current_user, oid, \'USAGE\') FROM pg_catalog.pg_namespace WHERE nspname = :schema');
            $check->execute(['schema' => $schema]);
            if ($check->fetchColumn() !== true) { throw new DatabaseException('DB_SCHEMA must exist and grant USAGE to the application role.'); }
            return $pdo;
          } catch (PDOException $e) {
            ++$this->failedAttempts;
            // No application SQL has run. A new connection is safe; never retry user statements.
            unset($pdo, $check);
            $category=DatabaseFailure::category($e);
            if ($attempt >= $attempts || !DatabaseFailure::reconnectable($e)) {
                throw new DatabaseException('PostgreSQL connection or session initialization failed (' . $category . ').', 0, $e, $category);
            }
            usleep(self::retryDelay($delay, $attempt)*1000);
          } catch (DatabaseException $e) {
            ++$this->failedAttempts;
            unset($pdo, $check);
            throw $e;
          }
        }
    }

    public static function retryDelay(int $base, int $attempt): int
    {
        $cap = min(5000, $base * (2 ** min(10, max(0, $attempt-1))));
        return random_int((int)ceil($cap/2), $cap);
    }

    private function field(string $key): string
    {
        $value = $this->config->get('database.' . $key);
        if (!is_string($value) || $value === '') { throw new DatabaseException('Missing database setting: ' . $key); }
        return $value;
    }

    private function number(string $key, int $min, int $max): int
    {
        $value = filter_var($this->config->get('database.' . $key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) { throw new DatabaseException('Invalid numeric database setting: ' . $key); }
        return $value;
    }
}
