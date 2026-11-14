<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Own one nonpersistent session. Call at operation boundaries, never mid-transaction. */
final class ConnectionManager
{
    private ?PDO $pdo=null;
    private int $lastProbe=0;
    private int $connections=0;
    private int $failures=0;
    private ?float $connectSeconds=null;
    private ?float $probeSeconds=null;

    public function __construct(private readonly ConnectionFactoryInterface $factory) {}

    public function connection(): PDO
    {
        if ($this->pdo!==null) {
            try {
                if ($this->pdo->inTransaction()) { throw new \LogicException('Cannot acquire a session at a transaction boundary while a transaction is active.'); }
                if (hrtime(true)-$this->lastProbe >= 5_000_000_000) { $this->probe(); }
                return $this->pdo;
            } catch (\Throwable $e) {
                if (!DatabaseFailure::reconnectable($e)) { throw $e; }
                $this->invalidate();
            }
        }
        $start=hrtime(true);
        try { $this->pdo=$this->factory->make('pgsql'); ++$this->connections; }
        catch (\Throwable $e) { ++$this->failures; throw $e; }
        finally { $this->connectSeconds=(hrtime(true)-$start)/1e9; }
        $this->lastProbe=hrtime(true);
        return $this->pdo;
    }

    public function probe(): float
    {
        if ($this->pdo===null) { $this->connection(); }
        if ($this->pdo->inTransaction()) { throw new \LogicException('Cannot probe inside a transaction.'); }
        $start=hrtime(true);
        try {
            $this->pdo->query('SELECT 1')->closeCursor();
            $this->lastProbe=hrtime(true);
            return $this->probeSeconds=($this->lastProbe-$start)/1e9;
        } catch (\Throwable $e) { ++$this->failures; if (DatabaseFailure::reconnectable($e)) { $this->invalidate(); } throw $e; }
    }

    public function invalidate(): void { $this->pdo=null; $this->lastProbe=0; }
    public function metrics(): array { return ['connections'=>$this->connections,'failures'=>$this->failures,'connect_seconds'=>$this->connectSeconds,'probe_seconds'=>$this->probeSeconds]; }
}
