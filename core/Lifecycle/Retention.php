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
            $s=$this->pdo->prepare('UPDATE projects SET retention_days=:days,revision=revision+1,updated_at=clock_timestamp() WHERE id=:id');$s->execute(['days'=>$days,'id'=>$project]);
            $changed=$s->rowCount()===1;
            if ($changed) (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('retention.policy_changed',['project_id'=>$project,'retention_days'=>$days],null,$project);
            return $changed;
        }, maxAttempts: 3);
    }
    public function policies(): array { return $this->pdo->query('SELECT id,name,slug,retention_days FROM projects ORDER BY id LIMIT 1000')->fetchAll(); }
    /** Bounded deletion. Caller owns transaction, policy changes serialize with deletion. */
    public function prune(int $limit=1000): int {
        if ($limit<1 || $limit>10000) { throw new \InvalidArgumentException('Retention batch must be 1..10000.'); }
        $this->lock();
        // Each policy gets an indexed, bounded candidate scan. Merge the two streams;
        // duplicate candidates (both policies expired) must count only once.
        $s=$this->pdo->prepare(self::pruneSql());
        $s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();return $s->rowCount();
    }
    public static function pruneSql(): string {
        return <<<'SQL'
WITH budget AS (SELECT CAST(:limit AS integer) AS n),
project_candidates AS (
    SELECT candidate.id,candidate.received_at FROM projects p
    CROSS JOIN LATERAL (
        SELECT l.id,l.received_at FROM log_events l
        WHERE l.project_id=p.id AND l.received_at<statement_timestamp()-p.retention_days*interval '1 day'
        ORDER BY l.received_at,l.id LIMIT (SELECT n FROM budget)
    ) candidate WHERE p.retention_days IS NOT NULL
), source_candidates AS (
    SELECT candidate.id,candidate.received_at FROM sources s
    CROSS JOIN LATERAL (
        SELECT l.id,l.received_at FROM log_events l
        WHERE l.source_id=s.id AND l.received_at<statement_timestamp()-s.retention_days*interval '1 day'
        ORDER BY l.received_at,l.id LIMIT (SELECT n FROM budget)
    ) candidate WHERE s.retention_days IS NOT NULL
), candidates AS (
    SELECT * FROM project_candidates UNION SELECT * FROM source_candidates
), expired AS (
    SELECT l.id FROM candidates c JOIN log_events l ON l.id=c.id
    ORDER BY c.received_at,c.id LIMIT (SELECT n FROM budget) FOR UPDATE OF l SKIP LOCKED
)
DELETE FROM log_events l USING expired e WHERE l.id=e.id
SQL;
    }
    public function run(int $limit=1000): int {
        return (new TransactionManager($this->pdo))->run(fn()=> $this->prune($limit), maxAttempts: 3);
    }
}
