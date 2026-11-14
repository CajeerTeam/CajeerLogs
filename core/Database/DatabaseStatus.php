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
            $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
            if ($pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'off') {
                return ['status' => 'down', 'server_version' => $version, 'error' => 'Database session is read-only.'];
            }
            $priv = $pdo->prepare("SELECT pg_catalog.has_schema_privilege(current_user, CAST(:schema AS text), 'CREATE')");
            $priv->execute(['schema' => $schema]);
            $canMigrate = (bool) $priv->fetchColumn();
            if (!$requireMigrations) { return ['status' => $canMigrate ? 'ok' : 'down', 'server_version' => $version, 'schema_create' => $canMigrate]; }
            $status = $this->app->container()->get(MigrationRunner::class)->status();
            if ($status['pending'] !== []) { return ['status' => 'down', 'server_version' => $version, 'migrations' => $status]; }
            // Check every expected relation and its read/write privileges in one round trip.
            $relations = $pdo->prepare(<<<'SQL'
                WITH expected(name) AS (VALUES ('users'), ('projects'), ('sources'), ('api_tokens'),
                    ('project_members'), ('auth_sessions'), ('auth_rate_limits'), ('ingest_receipts'), ('ingest_nonces'), ('log_events'), ('audit_log'), ('jobs'), ('settings'), ('webhooks'), ('extensions'), ('integration_webhooks'), ('webhook_deliveries'), ('webhook_attempts'), ('job_attempts'), ('scheduler_runs')),
                checked AS (
                    SELECT name, pg_catalog.to_regclass(pg_catalog.format('%I.%I', CAST(:schema AS text), name))::oid AS relation
                    FROM expected
                )
                SELECT name FROM checked WHERE relation IS NULL
                    OR NOT pg_catalog.has_table_privilege(current_user, relation, 'SELECT')
                    OR NOT pg_catalog.has_table_privilege(current_user, relation, 'INSERT')
                    OR NOT pg_catalog.has_table_privilege(current_user, relation, 'UPDATE')
                    OR (name IN ('project_members','auth_sessions','auth_rate_limits','ingest_receipts','ingest_nonces','jobs','job_attempts','log_events','webhook_deliveries','webhook_attempts') AND NOT pg_catalog.has_table_privilege(current_user, relation, 'DELETE'))
                ORDER BY name
                SQL);
            $relations->execute(['schema' => $schema]);
            $missing = $relations->fetchAll(PDO::FETCH_COLUMN);
            if ($missing !== []) {
                return ['status' => 'down', 'server_version' => $version, 'error' => 'Missing or inaccessible database tables.', 'tables' => $missing];
            }
            return ['status' => 'ok', 'server_version' => $version, 'migrations' => $status];
        } catch (\Throwable $error) {
            return ['status' => 'down', 'error' => $error instanceof DatabaseException ? $error->getMessage() : 'Database query failed.'];
        }
    }
}
