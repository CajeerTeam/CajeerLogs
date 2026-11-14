<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;
use PDOException;

/** Owns a single PDO transaction; callbacks must contain database-only effects. */
final class TransactionManager
{
    private int $depth=0;
    private int $sequence=0;
    private bool $rollbackOnly=false;
    private ?\Throwable $conflict=null;
    private static ?\WeakMap $unusable=null;
    private static array $metrics=['attempts'=>0,'commits'=>0,'rollbacks'=>0,'retries'=>0,'conflicts'=>0,'savepoints'=>0,'cleanup_failures'=>0,'uncertain_commits'=>0,'duration_seconds'=>0.0];

    public function __construct(private readonly PDO $pdo) {}
    public static function metrics(): array { return self::$metrics; }
    public static function usable(PDO $pdo): bool { return !isset(self::$unusable[$pdo]); }
    private function poison(): void { self::$unusable??=new \WeakMap(); self::$unusable[$this->pdo]=true; }
    public static function retryable(\Throwable $e): bool {
        return $e instanceof PDOException && in_array((string)($e->errorInfo[0]??$e->getCode()),['40001','40P01'],true);
    }
    public static function retryDelay(int $attempt): int {
        $cap=min(250,10*(2**min(5,max(0,$attempt-1))));
        return random_int((int)ceil($cap/2),$cap);
    }
    private function command(string $sql): void {
        if ($this->pdo->exec($sql)===false) throw new DatabaseException('Transaction control failed.',0,null,'query_error');
    }
    private function assertActive(): void {
        if (!$this->pdo->inTransaction()) { $this->poison(); throw new \LogicException('Transaction callback changed transaction ownership.'); }
    }
    /** Default is no replay. Opt in only for callbacks whose effects are entirely transactional.
     * Nested calls use savepoints, inherit isolation and never retry independently. */
    public function run(callable $callback, string $isolation='READ COMMITTED', int $maxAttempts=1, bool $readOnly=false): mixed {
        if (!in_array($isolation,['READ COMMITTED','REPEATABLE READ','SERIALIZABLE'],true) || $maxAttempts<1 || $maxAttempts>5) throw new \InvalidArgumentException('Invalid transaction options.');
        if (!self::usable($this->pdo)) throw new DatabaseException('Transaction session must be replaced.',0,null,'connection_lost');
        if ($this->depth>0) {
            if ($isolation!=='READ COMMITTED' || $maxAttempts!==1 || $readOnly) throw new \LogicException('Nested transaction options must inherit the outer transaction.');
            return $this->nested($callback);
        }
        if ($this->pdo->inTransaction()) throw new \LogicException('Cannot take ownership of an external transaction.');
        for ($attempt=1;;++$attempt) {
            $started=hrtime(true); ++self::$metrics['attempts']; $commitStarted=false; $owned=false;
            $this->rollbackOnly=false; $this->conflict=null;
            try {
                if (!$this->pdo->beginTransaction()) throw new DatabaseException('Cannot begin transaction.',0,null,'query_error');
                $owned=true; $this->depth=1;
                $this->command('SET TRANSACTION ISOLATION LEVEL '.$isolation.($readOnly?' READ ONLY':' READ WRITE'));
                if (!$readOnly) $this->command('SET LOCAL synchronous_commit=on');
                $value=$callback($this);
                $this->assertActive();
                if (!self::usable($this->pdo)) throw new DatabaseException('Transaction session must be replaced.',0,null,'connection_lost');
                if ($this->rollbackOnly) throw $this->conflict??new \LogicException('Transaction marked rollback-only.');
                // PostgreSQL COMMIT on an aborted transaction may silently perform ROLLBACK.
                // Probe before COMMIT so a swallowed SQL error cannot yield false success.
                $probe=$this->pdo->query('SELECT 1');
                if ($probe===false) throw new DatabaseException('Transaction probe failed.',0,null,'query_error');
                $probe->closeCursor();
                $commitStarted=true;
                if (!$this->pdo->commit()) throw new DatabaseException('Commit outcome is unknown.',0,null,'connection_lost');
                ++self::$metrics['commits']; return $value;
            } catch (\Throwable $e) {
                $retry=self::retryable($e); if ($retry) ++self::$metrics['conflicts'];
                $clean=true;
                try {
                    if ($owned && $this->pdo->inTransaction()) {
                        if (!$this->pdo->rollBack()) throw new \RuntimeException('Rollback failed.');
                        ++self::$metrics['rollbacks'];
                    }
                } catch (\Throwable) { $clean=false; $this->poison(); ++self::$metrics['cleanup_failures']; }
                $state=$e instanceof PDOException ? (string)($e->errorInfo[0]??$e->getCode()) : '';
                $knownAbort=$retry || str_starts_with($state,'23') || in_array($state,['25P02','40002'],true);
                if ($commitStarted && !$knownAbort) {
                    // Never replay an ambiguous COMMIT, even when a later rollback appears successful.
                    ++self::$metrics['uncertain_commits']; $this->poison();
                    throw new DatabaseException('Commit outcome is unknown; reconcile durable state before retrying.',0,$e,'connection_lost');
                }
                if (!$clean) throw new DatabaseException('Transaction cleanup failed; replace the connection.',0,$e,'connection_lost');
                if (DatabaseFailure::reconnectable($e)) $this->poison();
                if (!$retry || !$owned || !self::usable($this->pdo) || $attempt >= $maxAttempts) throw $e;
                ++self::$metrics['retries'];
            } finally { $this->depth=0; self::$metrics['duration_seconds']+=(hrtime(true)-$started)/1e9; }
            usleep(self::retryDelay($attempt)*1000);
        }
    }
    private function nested(callable $callback): mixed {
        $this->assertActive();
        $name='cajeer_sp_'.(++$this->sequence);
        $this->command('SAVEPOINT '.$name); ++$this->depth; ++self::$metrics['savepoints'];
        try {
            $value=$callback($this); $this->assertActive();
            $this->command('RELEASE SAVEPOINT '.$name); return $value;
        } catch (\Throwable $e) {
            if (self::retryable($e)) { $this->rollbackOnly=true; $this->conflict=$e; }
            try { $this->assertActive(); $this->command('ROLLBACK TO SAVEPOINT '.$name); $this->command('RELEASE SAVEPOINT '.$name); }
            catch (\Throwable) { $this->rollbackOnly=true; $this->poison(); ++self::$metrics['cleanup_failures']; throw new DatabaseException('Savepoint cleanup failed; abort outer transaction.',0,$e,'connection_lost'); }
            throw $e;
        } finally { --$this->depth; }
    }
}
