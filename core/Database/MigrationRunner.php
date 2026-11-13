<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory, private readonly string $schema) {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) || str_starts_with($schema, 'pg_') || $schema === 'information_schema') { throw new DatabaseException('Invalid migration schema.'); }
    }

    /** @return array<string,array{sql:string,checksum:string}> */
    private function files(): array
    {
        $paths = glob($this->directory . '/*.sql');
        if (!$paths) { throw new DatabaseException('No PostgreSQL migrations found.'); }
        sort($paths, SORT_STRING);
        $files = [];
        $expected = 1;
        foreach ($paths as $path) {
            $name = basename($path);
            if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/D', $name, $m) || (int) $m[1] !== $expected++) { throw new DatabaseException('Migration filenames must be sequential and unique.'); }
            $sql = file_get_contents($path);
            if ($sql === false || trim($sql) === '') { throw new DatabaseException('Unreadable or empty migration: ' . $name); }
            $files[$name] = ['sql' => $sql, 'checksum' => hash('sha256', $sql)];
        }
        return $files;
    }

    private function table(): string { return '"' . $this->schema . '".schema_migrations'; }

    private function history(): array
    {
        $query = $this->pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
        $query->execute(['name' => $this->table()]);
        if (!$query->fetchColumn()) { return []; }
        return $this->pdo->query('SELECT version, checksum FROM ' . $this->table() . ' ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function compare(array $files, array $history): array
    {
        $pending = [];
        $seenPending = false;
        foreach ($files as $name => $file) {
            if (!array_key_exists($name, $history)) { $pending[] = $name; $seenPending = true; continue; }
            if ($seenPending || !hash_equals($file['checksum'], (string) $history[$name])) { throw new DatabaseException('Migration history mismatch: ' . $name); }
            unset($history[$name]);
        }
        if ($history !== []) { throw new DatabaseException('Database contains unknown migrations; refusing downgrade.'); }
        return $pending;
    }

    /** Read-only status; never creates history or changes schema. */
    public function status(): array
    {
        $files = $this->files();
        $pending = $this->compare($files, $this->history());
        return ['applied' => array_values(array_diff(array_keys($files), $pending)), 'pending' => $pending];
    }

    /** All pending migrations and their checksums commit atomically. */
    public function migrate(): array
    {
        $files = $this->files();
        if ($this->pdo->inTransaction()) { throw new DatabaseException('Migration runner requires its own transaction.'); }
        $this->pdo->beginTransaction();
        $current = 'migration lock/history';
        try {
            $lock = $this->pdo->prepare("SELECT pg_advisory_xact_lock(hashtextextended(:namespace, 0))");
            $lock->execute(['namespace' => 'cajeerlogs:migrations:' . $this->schema]);
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table() . ' (version text PRIMARY KEY, checksum text NOT NULL CHECK (length(checksum) = 64), applied_at timestamptz NOT NULL DEFAULT now())');
            $pending = $this->compare($files, $this->history());
            $insert = $this->pdo->prepare('INSERT INTO ' . $this->table() . ' (version, checksum) VALUES (:version, :checksum)');
            foreach ($pending as $current) {
                $this->pdo->exec($files[$current]['sql']);
                $insert->execute(['version' => $current, 'checksum' => $files[$current]['checksum']]);
            }
            $this->pdo->commit();
            return $pending;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($error instanceof DatabaseException) { throw $error; }
            throw new DatabaseException('Migration failed at ' . $current . '; transaction rolled back.', 0, $error);
        }
    }
}
