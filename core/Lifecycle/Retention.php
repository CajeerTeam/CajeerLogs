<?php
declare(strict_types=1);
namespace Cajeer\Logs\Lifecycle;

use Cajeer\Logs\Database\TransactionManager;
use PDO;

final class Retention
{
    public function __construct(private readonly PDO $pdo) {}
    private function lock(): void {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Retention requires a transaction.'); }
        $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:retention:' || current_schema(),0))")->closeCursor();
    }
    public function set(string $project,?int $days): bool {
        if ($days!==null && ($days<1 || $days>3650)) { throw new \InvalidArgumentException('Retention days must be 1..3650.'); }
        return (new TransactionManager($this->pdo))->run(function () use ($project,$days) {
            $this->lock();
            $s=$this->pdo->prepare('UPDATE projects SET retention_days=:days WHERE id=:id');$s->execute(['days'=>$days,'id'=>$project]);
            $changed=$s->rowCount()===1;return $changed;
        }, maxAttempts: 3);
    }
    public function policies(): array { return $this->pdo->query('SELECT id,name,slug,retention_days FROM projects ORDER BY id LIMIT 1000')->fetchAll(); }
    /** Bounded deletion. Caller owns transaction, policy changes serialize with deletion. */
    public function prune(int $limit=1000): int {
        if ($limit<1 || $limit>10000) { throw new \InvalidArgumentException('Retention batch must be 1..10000.'); }
        $this->lock();
        $s=$this->pdo->prepare("WITH expired AS (SELECT l.id FROM log_events l JOIN projects p ON p.id=l.project_id WHERE p.retention_days IS NOT NULL AND l.received_at<statement_timestamp()-p.retention_days*interval '1 day' ORDER BY l.received_at,l.id LIMIT :limit FOR UPDATE OF l SKIP LOCKED) DELETE FROM log_events l USING expired e WHERE l.id=e.id");
        $s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();return $s->rowCount();
    }
    public function run(int $limit=1000): int {
        return (new TransactionManager($this->pdo))->run(fn()=> $this->prune($limit), maxAttempts: 3);
    }
}
