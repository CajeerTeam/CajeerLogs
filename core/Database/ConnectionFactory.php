<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use Cajeer\Logs\Config\ConfigRepository;
use PDO;
use PDOException;

final class ConnectionFactory implements ConnectionFactoryInterface
{
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
        if ($connection !== 'pgsql' || $this->config->get('database.default') !== 'pgsql') {
            throw new DatabaseException('Only PostgreSQL is supported in 0.0.7.');
        }
        $schema = $this->schema();
        $host = $this->field('host');
        $database = $this->field('database');
        $username = $this->field('username');
        $password = $this->config->get('database.password');
        if (!is_string($password) || $password === '' || $password === 'change_me' || str_contains($password, "\0")) {
            throw new DatabaseException('DB_PASSWORD must be a non-placeholder password.');
        }
        $port = $this->number('port', 1, 65535);
        $timeout = $this->number('connect_timeout', 1, 60);
        $statement = $this->number('statement_timeout_ms', 1, 600000);
        $lock = $this->number('lock_timeout_ms', 1, 600000);
        $ssl = $this->field('sslmode');
        if (!in_array($ssl, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
            throw new DatabaseException('Invalid DB_SSLMODE.');
        }
        // Restrict DSN fields rather than interpolating arbitrary libpq connection options.
        if (!preg_match('~^[A-Za-z0-9_.:/-]+$~D', $host) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $database) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $username)) {
            throw new DatabaseException('Invalid DB_HOST, DB_DATABASE or DB_USERNAME.');
        }
        $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=$ssl";
        $cert = $this->config->get('database.sslrootcert');
        if ($cert !== '') {
            if (!is_string($cert) || !preg_match('~^/[A-Za-z0-9_./-]+$~D', $cert) || !is_readable($cert)) { throw new DatabaseException('Invalid DB_SSLROOTCERT.'); }
            $dsn .= ';sslrootcert=' . $cert;
        }
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseException('Missing PHP extension pdo_pgsql.');
        }
        try {
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES => false]);
            if ((int) $pdo->query('SHOW server_version_num')->fetchColumn() < 170000) { throw new DatabaseException('PostgreSQL 17 or newer is required.'); }
            $pdo->exec("SET search_path TO \"$schema\", pg_catalog, pg_temp");
            $pdo->exec("SET TIME ZONE 'UTC'; SET statement_timeout = $statement; SET lock_timeout = $lock");
            $pdo->exec("SET client_encoding TO 'UTF8'");
            $check = $pdo->prepare('SELECT pg_catalog.has_schema_privilege(current_user, oid, \'USAGE\') FROM pg_catalog.pg_namespace WHERE nspname = :schema');
            $check->execute(['schema' => $schema]);
            if ($check->fetchColumn() !== true) { throw new DatabaseException('DB_SCHEMA must exist and grant USAGE to the application role.'); }
            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseException('PostgreSQL connection or session initialization failed (check credentials, TLS and permissions).', 0, $e);
        }
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
