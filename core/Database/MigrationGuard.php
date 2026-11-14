<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Session shared lock held across the business operation, including its own transactions. */
final class MigrationGuard
{
    private bool $held=false;
    public function __construct(private readonly PDO $pdo,private readonly string $key)
    {
        $q=$pdo->prepare('SELECT pg_try_advisory_lock_shared(hashtextextended(:key,0))');
        $q->execute(['key'=>$key]); $this->held=$q->fetchColumn()===true; $q->closeCursor();
        if (!$this->held) throw new DatabaseException('Schema migration in progress.');
    }
    public function release(): void {
        if (!$this->held) return;
        $this->held=false;
        try { $q=$this->pdo->prepare('SELECT pg_advisory_unlock_shared(hashtextextended(:key,0))'); $q->execute(['key'=>$this->key]); $q->closeCursor(); }
        catch (\Throwable) { /* Lost session releases the lock at the server. Never mask business errors. */ }
    }
    public function __destruct() { $this->release(); }
}
