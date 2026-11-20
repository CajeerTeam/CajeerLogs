<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Durable database-wide quarantine. A process crash must not publish a partial restore. */
final class RestoreFence
{
    private const KEY = 'cajeerlogs.restore_state';
    private const PENDING_SQL = "'cajeerlogs.restore_state=pending'";

    /** Durable owner-managed database comment; also honors legacy GUC quarantines. */
    public static function pending(PDO $pdo): bool
    {
        return PostgresBoolean::required($pdo->query(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_catalog.pg_database d
                WHERE d.datname=pg_catalog.current_database() AND (
                    pg_catalog.shobj_description(d.oid,'pg_database')='cajeerlogs.restore_state=pending'
                    OR EXISTS (
                        SELECT 1 FROM pg_catalog.pg_db_role_setting s
                        WHERE s.setdatabase=d.oid AND s.setrole=0
                          AND 'cajeerlogs.restore_state=pending'=ANY(s.setconfig)
                    )
                )
            )
            SQL)->fetchColumn());
    }

    public static function assertAvailable(PDO $pdo): void
    {
        if (self::pending($pdo)) {
            throw new DatabaseException('Database restore is incomplete; use a fresh empty target and repeat the restore.');
        }
    }

    /** Only called by BackupManager on the dedicated target, as its database owner. */
    public static function quarantine(PDO $pdo, string $database): void
    {
        self::checkTarget($pdo, $database);
        if ($pdo->inTransaction()) throw new DatabaseException('Restore quarantine requires autocommit.');
        // ALTER DATABASE SET an unknown custom GUC requires elevated privileges
        // on PostgreSQL 17/18. COMMENT ON DATABASE is a durable transactional
        // owner-only marker and needs no cluster-wide grants.
        if ($pdo->exec('COMMENT ON DATABASE "'.$database.'" IS '.self::PENDING_SQL) === false || !self::pending($pdo)) {
            throw new DatabaseException('Could not quarantine restore database.');
        }
    }

    /** Clear the durable marker only inside the final validated restore transaction. */
    public static function publish(PDO $pdo, string $database): void
    {
        self::checkTarget($pdo, $database);
        if (!$pdo->inTransaction() || !self::pending($pdo)) {
            throw new DatabaseException('Restore publication requires a quarantined database and transaction.');
        }
        if ($pdo->exec('COMMENT ON DATABASE "'.$database.'" IS NULL') === false || self::pending($pdo)) {
            throw new DatabaseException('Could not publish restored database.');
        }
    }

    private static function checkTarget(PDO $pdo, string $database): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $database) || $pdo->query('SELECT pg_catalog.current_database()')->fetchColumn() !== $database) {
            throw new DatabaseException('Restore fence target mismatch.');
        }
    }
}
