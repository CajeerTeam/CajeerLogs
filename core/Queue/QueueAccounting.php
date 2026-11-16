<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use PDO;
use Cajeer\Logs\Database\TransactionManager;

/** Exact durable queue capacity. Full scans are explicit maintenance operations only. */
final class QueueAccounting
{
    public function __construct(private readonly PDO $pdo) {}

    public function snapshot(): array
    {
        $row=$this->pdo->query("SELECT active_jobs,active_events,active_bytes FROM queue_accounting WHERE queue='ingest-v1'")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Queue accounting is missing.');
        return array_map('intval',$row);
    }

    private const ACTUAL="SELECT count(*) AS active_jobs,COALESCE(sum(payload_events),0) AS active_events,COALESCE(sum(payload_bytes),0) AS active_bytes FROM jobs WHERE queue='ingest-v1' AND status IN ('pending','running','dead')";

    /** One MVCC statement: concurrent commits cannot create a false mismatch. */
    public function verify(): array
    {
        $row=$this->pdo->query("WITH actual AS (".self::ACTUAL.") SELECT a.active_jobs,a.active_events,a.active_bytes,c.active_jobs AS stored_jobs,c.active_events AS stored_events,c.active_bytes AS stored_bytes FROM actual a LEFT JOIN queue_accounting c ON c.queue='ingest-v1'")->fetch(PDO::FETCH_ASSOC);
        $actual=['active_jobs'=>(int)$row['active_jobs'],'active_events'=>(int)$row['active_events'],'active_bytes'=>(int)$row['active_bytes']];
        $stored=$row['stored_jobs']===null?null:['active_jobs'=>(int)$row['stored_jobs'],'active_events'=>(int)$row['stored_events'],'active_bytes'=>(int)$row['stored_bytes']];
        return ['valid'=>$actual===$stored,'stored'=>$stored,'actual'=>$actual];
    }

    /** Explicit maintenance; block writes before reading jobs, never alter payloads. */
    public function reconcile(): array
    {
        return (new TransactionManager($this->pdo))->run(function () {
            $this->pdo->exec('LOCK TABLE jobs IN SHARE ROW EXCLUSIVE MODE');
            $before=$this->verify();
            $this->pdo->exec("INSERT INTO queue_accounting(queue,active_jobs,active_events,active_bytes) SELECT 'ingest-v1',a.* FROM (".self::ACTUAL.") a ON CONFLICT(queue) DO UPDATE SET active_jobs=EXCLUDED.active_jobs,active_events=EXCLUDED.active_events,active_bytes=EXCLUDED.active_bytes");
            return ['repaired'=>!$before['valid']]+$this->verify();
        },maxAttempts:3);
    }
}
