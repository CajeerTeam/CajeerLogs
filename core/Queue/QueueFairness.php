<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Exact project budgets and weighted virtual-finish scheduling, stored in PostgreSQL.
 * All claims/policy changes lock one project row; job triggers maintain counters.
 * No process-local scheduling state, so independently supervised workers cooperate. */
final class QueueFairness
{
    private const LIMITS=['weight'=>[1,16], 'max_jobs'=>[0,100000000], 'max_events'=>[0,1000000000],
        'max_bytes'=>[0,1099511627776], 'max_running_jobs'=>[0,1024]];

    public function __construct(private readonly PDO $pdo) {}

    public static function policy(array $input): array
    {
        if (array_keys($input)!==array_keys(self::LIMITS)) {
            throw new \InvalidArgumentException('Specify all project queue policy fields.');
        }
        $values=[];
        foreach (self::LIMITS as $name=>[$min,$max]) {
            $v=$input[$name];
            if (is_bool($v) || !(is_int($v) || is_string($v) && preg_match('/^(0|[1-9][0-9]*)$/D',$v)))
                throw new \InvalidArgumentException('Invalid project queue policy: '.$name);
            $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($n===false) throw new \InvalidArgumentException('Invalid project queue policy: '.$name);
            $values[$name]=(int)$n;
        }
        return $values;
    }

    public static function validProject(string $project): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/DiD',$project);
    }

    /** Called after global admission under the same transaction, before quota consumption.
     * Per-project capacity denial is deliberately NOT a global overload latch. */
    public function admit(string $project,int $events,int $bytes): ?HttpException
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Project admission requires a transaction.');
        if ($events<1 || $events>100 || $bytes<1 || !self::validProject($project))
            throw new \InvalidArgumentException('Invalid project admission.');
        $q=$this->pdo->prepare('SELECT active_jobs,active_events,active_bytes,max_jobs,max_events,max_bytes FROM queue_project_state WHERE project_id=CAST(:id AS uuid) FOR UPDATE');
        $q->execute(['id'=>$project]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Project queue accounting is missing.');
        foreach (['jobs'=>1,'events'=>$events,'bytes'=>$bytes] as $name=>$incoming) {
            $max=(int)$row['max_'.$name];
            if ($max>0 && $incoming>$max-(int)$row['active_'.$name]) {
                $this->pdo->prepare('UPDATE queue_project_state SET rejected=rejected+1 WHERE project_id=CAST(:id AS uuid)')->execute(['id'=>$project]);
                return new HttpException(503,'queue_project_full','Project ingest queue capacity exceeded ('.$name.').',['Retry-After'=>'5']);
            }
        }
        return null;
    }

    /** Lock the FK parent before queue_project_state: PostgreSQL checks the
     * projects parent row during queue counter/fair-dispatch updates. An HTTP
     * admission can already hold that parent row while waiting for queue state.
     * Acquiring queue state first in a worker creates a real deadlock cycle.
     */
    private function lockParent(string $project, bool $skipLocked=false): bool
    {
        $sql='SELECT id FROM projects WHERE id=CAST(:project AS uuid) FOR KEY SHARE'
            .($skipLocked?' SKIP LOCKED':'');
        $q=$this->pdo->prepare($sql);
        $q->execute(['project'=>$project]);
        return $q->fetchColumn()!==false;
    }

    /** Lock the least-served ready project, in FK-parent -> queue-state order.
     * Do not lock a queue-state row while waiting on its projects FK parent.
     * Recheck readiness under the row lock: another worker may have claimed it.
     */
    public function selectReadyProject(): ?array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Fair claim requires a transaction.');
        $where=<<<'SQL'
            (
                (s.pending_jobs>0 AND (s.max_running_jobs=0 OR s.running_jobs<s.max_running_jobs)
                    AND EXISTS (SELECT 1 FROM jobs j WHERE j.queue='ingest-v1' AND j.status='pending'
                        AND j.project_id=s.project_id AND j.available_at<=statement_timestamp()))
                OR (s.running_jobs>0 AND EXISTS (SELECT 1 FROM jobs j WHERE j.queue='ingest-v1'
                        AND j.status='running' AND j.project_id=s.project_id AND j.lease_until<=statement_timestamp()))
            )
            SQL;
        // A bounded ordered shortlist prevents holding a large set of parent
        // locks on systems with many projects, while preserving SKIP LOCKED.
        $candidates=$this->pdo->query("SELECT s.project_id FROM queue_project_state s WHERE $where
            ORDER BY s.virtual_finish,s.project_id LIMIT 64")->fetchAll(PDO::FETCH_COLUMN);
        if ($candidates===[]) return null; // Avoid preparing unused statements; no eligible project.
        $q=$this->pdo->prepare("SELECT s.project_id,s.weight,s.max_running_jobs,s.running_jobs
            FROM queue_project_state s WHERE s.project_id=CAST(:project AS uuid) AND $where
            FOR UPDATE OF s SKIP LOCKED");
        foreach ($candidates as $project) {
            if (!$this->lockParent((string)$project,true)) continue;
            $q->execute(['project'=>$project]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            $q->closeCursor();
            if ($row) return $row;
        }
        return null;
    }

    /** A lease recovery takes the same FK-parent -> queue-state lock order. */
    public function selectExpiredProject(): ?string
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Expired lease scan requires a transaction.');
        $where=<<<'SQL'
            s.running_jobs>0 AND EXISTS (
                SELECT 1 FROM jobs j WHERE j.queue='ingest-v1'
                    AND j.project_id=s.project_id AND j.status='running'
                    AND j.lease_until<=statement_timestamp()
            )
            SQL;
        $candidates=$this->pdo->query("SELECT s.project_id FROM queue_project_state s WHERE $where
            ORDER BY s.virtual_finish,s.project_id LIMIT 64")->fetchAll(PDO::FETCH_COLUMN);
        if ($candidates===[]) return null; // Avoid preparing unused statements; no eligible project.
        $q=$this->pdo->prepare("SELECT s.project_id FROM queue_project_state s
            WHERE s.project_id=CAST(:project AS uuid) AND $where
            FOR UPDATE OF s SKIP LOCKED");
        foreach ($candidates as $project) {
            if (!$this->lockParent((string)$project,true)) continue;
            $q->execute(['project'=>$project]);
            $row=$q->fetchColumn();
            $q->closeCursor();
            if (is_string($row)) return $row;
        }
        return null;
    }

    /** Lock project policy before acquiring more jobs in a worker microbatch. */
    public function lockProject(string $project): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Project claim requires a transaction.');
        if (!$this->lockParent($project)) throw new \RuntimeException('Project queue parent is missing.');
        $q=$this->pdo->prepare('SELECT weight,max_running_jobs,running_jobs FROM queue_project_state WHERE project_id=CAST(:id AS uuid) FOR UPDATE');
        $q->execute(['id'=>$project]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Project queue accounting is missing.');
        return $row;
    }

    /** Advance by one virtual job per claim. Weight 4 gets 4x as many turns as weight 1.
     * Calling only for successfully claimed jobs avoids charging deferred leases. */
    public function dispatched(string $project,int $count=1): void
    {
        if (!$this->pdo->inTransaction() || $count<1 || $count>16) throw new \LogicException('Dispatch must be transactional and bounded.');
        $q=$this->pdo->prepare('UPDATE queue_project_state SET virtual_finish=virtual_finish+((1024*CAST(:count AS bigint)+weight-1)/weight),dispatched=dispatched+CAST(:more AS bigint),last_claim_at=clock_timestamp() WHERE project_id=CAST(:project AS uuid)');
        $q->execute(['count'=>$count,'more'=>$count,'project'=>$project]);
        if ($q->rowCount()!==1) throw new \RuntimeException('Project fairness state missing.');
    }

    public function snapshot(?string $project=null): array
    {
        if ($project!==null && !self::validProject($project)) throw new \InvalidArgumentException('Invalid project UUID.');
        $sql='SELECT project_id,weight,max_jobs,max_events,max_bytes,max_running_jobs,active_jobs,pending_jobs,running_jobs,active_events,active_bytes,virtual_finish,dispatched,rejected,last_claim_at,accepted_jobs_total,accepted_events_total,completed_jobs_total,completed_events_total,retried_jobs_total,dead_jobs_total,lease_expirations_total,delivery_latency_ms_total,delivery_le_1,delivery_le_5,delivery_le_30,delivery_le_300 FROM queue_project_state';
        $sql.=$project===null?' ORDER BY project_id LIMIT 100':' WHERE project_id=CAST(:project AS uuid)';
        $q=$this->pdo->prepare($sql);$q->execute($project===null?[]:['project'=>$project]);
        return array_map(static function(array $row): array {
            foreach ($row as $key=>$value) if (!in_array($key,['project_id','last_claim_at'],true) && $value!==null) $row[$key]=(int)$value;
            return $row;
        },$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function configure(string $project,array $policy): array
    {
        if (!self::validProject($project)) throw new \InvalidArgumentException('Invalid project UUID.');
        $policy=self::policy($policy);
        return (new TransactionManager($this->pdo))->run(function() use ($project,$policy): array {
            $q=$this->pdo->prepare('UPDATE queue_project_state SET weight=:weight,max_jobs=:max_jobs,max_events=:max_events,max_bytes=:max_bytes,max_running_jobs=:max_running_jobs WHERE project_id=CAST(:project AS uuid) RETURNING project_id');
            $q->execute($policy+['project'=>$project]);
            if ($q->fetchColumn()===false) throw new \InvalidArgumentException('Project does not exist.');
            (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('queue.project_policy_updated',[
                'project_id'=>$project,'policy'=>$policy
            ],null,$project,'success','system');
            return $this->snapshot($project)[0];
        },maxAttempts:3);
    }

    /** Exact MVCC-snapshot comparison of all projects; expensive operator-only query. */
    public function verify(): array
    {
        $q=$this->pdo->query(<<<'SQL'
            WITH actual AS (
                SELECT p.id, count(j.id) FILTER(WHERE j.status IN ('pending','running','dead')) AS a,
                    count(j.id) FILTER(WHERE j.status='pending') AS p,
                    count(j.id) FILTER(WHERE j.status='running') AS r,
                    coalesce(sum(j.payload_events) FILTER(WHERE j.status IN ('pending','running','dead')),0) AS e,
                    coalesce(sum(j.payload_bytes) FILTER(WHERE j.status IN ('pending','running','dead')),0) AS b
                FROM projects p LEFT JOIN jobs j ON j.project_id=p.id AND j.queue='ingest-v1'
                GROUP BY p.id
            )
            SELECT a.id::text FROM actual a LEFT JOIN queue_project_state s ON s.project_id=a.id
            WHERE s.project_id IS NULL OR s.active_jobs IS DISTINCT FROM a.a
                OR s.pending_jobs IS DISTINCT FROM a.p OR s.running_jobs IS DISTINCT FROM a.r
                OR s.active_events IS DISTINCT FROM a.e OR s.active_bytes IS DISTINCT FROM a.b
            ORDER BY a.id LIMIT 21
            SQL);
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);
        return ['valid'=>$ids===[],'sample_projects'=>array_slice($ids,0,20),'truncated'=>count($ids)>20];
    }

    /** Maintenance only. Block writers before the aggregation. Do not modify jobs. */
    public function reconcile(): array
    {
        return (new TransactionManager($this->pdo))->run(function(): array {
            $this->pdo->exec('LOCK TABLE jobs IN SHARE ROW EXCLUSIVE MODE');
            $this->pdo->exec('LOCK TABLE projects IN SHARE ROW EXCLUSIVE MODE');
            $before=$this->verify();
            $this->pdo->exec(<<<'SQL'
                INSERT INTO queue_project_state(project_id)
                SELECT id FROM projects ON CONFLICT(project_id) DO NOTHING
                SQL);
            $this->pdo->exec(<<<'SQL'
                WITH actual AS (
                    SELECT p.id, count(j.id) FILTER(WHERE j.status IN ('pending','running','dead')) AS a,
                        count(j.id) FILTER(WHERE j.status='pending') AS p,
                        count(j.id) FILTER(WHERE j.status='running') AS r,
                        coalesce(sum(j.payload_events) FILTER(WHERE j.status IN ('pending','running','dead')),0) AS e,
                        coalesce(sum(j.payload_bytes) FILTER(WHERE j.status IN ('pending','running','dead')),0) AS b
                    FROM projects p LEFT JOIN jobs j ON j.project_id=p.id AND j.queue='ingest-v1'
                    GROUP BY p.id
                )
                UPDATE queue_project_state s SET active_jobs=a.a,pending_jobs=a.p,running_jobs=a.r,
                    active_events=a.e,active_bytes=a.b FROM actual a WHERE s.project_id=a.id
                SQL);
            return ['repaired'=>!$before['valid']]+$this->verify();
        },maxAttempts:3);
    }
}
