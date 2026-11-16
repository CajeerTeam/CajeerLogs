<?php
declare(strict_types=1);
namespace Cajeer\Logs\Lifecycle;

use Cajeer\Logs\Database\TransactionManager;
use PDO;

final class Retention
{
    public function __construct(private readonly PDO $pdo, private readonly int $timeoutMs=5000) {
        if ($timeoutMs<100 || $timeoutMs>60000) throw new \InvalidArgumentException('Retention timeout must be 100..60000 ms.');
    }
    /** Optional half-open UTC daily window; midnight crossing is supported. */
    public static function windowOpen(string $window,?int $timestamp=null): bool {
        if ($window==='') return true;
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/D',$window,$m)) throw new \InvalidArgumentException('Invalid RETENTION_WINDOW_UTC.');
        $start=(int)$m[1]*60+(int)$m[2];$end=(int)$m[3]*60+(int)$m[4];
        if ($start===$end) throw new \InvalidArgumentException('Invalid RETENTION_WINDOW_UTC: equal boundaries.');
        $timestamp??=time();$minute=(int)gmdate('G',$timestamp)*60+(int)gmdate('i',$timestamp);
        return $start<$end?$minute>=$start&&$minute<$end:$minute>=$start||$minute<$end;
    }
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
        if (!$this->pdo->inTransaction()) throw new \LogicException('Retention requires a transaction.');
        $started=hrtime(true);
        $this->pdo->exec('SET LOCAL statement_timeout='.$this->timeoutMs);
        $this->pdo->exec('SET LOCAL lock_timeout='.min(1000,$this->timeoutMs));
        $this->lock();
        // Each policy gets an indexed, bounded candidate scan. Merge the two streams;
        // duplicate candidates (both policies expired) must count only once.
        $s=$this->pdo->prepare(self::pruneSql());
        $s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();$row=$s->fetch();$s->closeCursor();
        $removed=(int)$row['removed'];
        $this->record('success',$limit,$removed,max(0,(int)$row['sampled']-$removed),$row['oldest'],(int)$row['sampled']>$limit,(int)((hrtime(true)-$started)/1e6));
        return $removed;
    }
    private static function candidateSql(bool $scoped=false): string {
        $sql= <<<'SQL'
WITH budget AS (SELECT CAST(:limit AS integer) AS n),
project_candidates AS (
    SELECT candidate.id,candidate.received_at FROM projects p
    CROSS JOIN LATERAL (
        SELECT l.id,l.received_at FROM log_events l
        WHERE l.project_id=p.id AND l.received_at<statement_timestamp()-p.retention_days*interval '1 day'
        ORDER BY l.received_at,l.id LIMIT (SELECT n+1 FROM budget)
    ) candidate WHERE p.retention_days IS NOT NULL
), source_candidates AS (
    SELECT candidate.id,candidate.received_at FROM sources s
    CROSS JOIN LATERAL (
        SELECT l.id,l.received_at FROM log_events l
        WHERE l.source_id=s.id AND l.received_at<statement_timestamp()-s.retention_days*interval '1 day'
        ORDER BY l.received_at,l.id LIMIT (SELECT n+1 FROM budget)
    ) candidate WHERE s.retention_days IS NOT NULL
), candidates AS (
    SELECT * FROM project_candidates UNION SELECT * FROM source_candidates
), sample AS (
    SELECT * FROM candidates ORDER BY received_at,id LIMIT (SELECT n+1 FROM budget)
)
SQL;
        if ($scoped) {
            $sql=str_replace("AS n)","AS n, CAST(:project AS uuid) AS project)",$sql);
            $sql=str_replace("WHERE p.retention_days IS NOT NULL","WHERE p.retention_days IS NOT NULL AND p.id=(SELECT project FROM budget)",$sql);
            $sql=str_replace("WHERE s.retention_days IS NOT NULL","WHERE s.retention_days IS NOT NULL AND s.project_id=(SELECT project FROM budget)",$sql);
        }
        return $sql;
    }
    public static function pruneSql(): string {
        return self::candidateSql().<<<'SQL'
, expired AS (
    SELECT l.id FROM candidates c JOIN log_events l ON l.id=c.id
    ORDER BY c.received_at,c.id LIMIT (SELECT n FROM budget) FOR UPDATE OF l SKIP LOCKED
) , deleted AS (
    DELETE FROM log_events l USING expired e WHERE l.id=e.id RETURNING l.id
)
SELECT (SELECT count(*) FROM deleted) AS removed, count(*) AS sampled, min(received_at) AS oldest FROM sample
SQL;
    }
    /** Read-only bounded estimate: exact only if the sample has not reached its cap. */
    public function preview(int $limit=1000,?string $project=null): array {
        if ($limit<1 || $limit>10000) throw new \InvalidArgumentException('Retention preview must be 1..10000.');
        return (new TransactionManager($this->pdo))->run(function () use ($limit,$project) {
            $this->pdo->exec('SET LOCAL statement_timeout='.$this->timeoutMs);
            if ($project!==null) { $check=$this->pdo->prepare('SELECT 1 FROM projects WHERE id=:id');$check->execute(['id'=>$project]);if (!$check->fetchColumn()) throw new \InvalidArgumentException('Unknown project.');$check->closeCursor(); }
            $sql=self::candidateSql($project!==null).' SELECT count(*) AS sampled,min(received_at) AS oldest FROM sample';
            $s=$this->pdo->prepare($sql);$s->bindValue(':limit',$limit,PDO::PARAM_INT);if ($project!==null) $s->bindValue(':project',$project);$s->execute();$r=$s->fetch();$s->closeCursor();
            $count=(int)$r['sampled'];return ['dry_run'=>true,'project_id'=>$project,'candidates'=>min($count,$limit),'more'=>$count>$limit,'oldest_received_at'=>$r['oldest'],'limit'=>$limit];
        }, readOnly:true);
    }
    private function record(string $status,int $limit,?int $removed,?int $remaining,?string $oldest,bool $more,?int $duration=null): void {
        // Serialize history with policy changes and deletion. Counter survives bounded history pruning.
        $this->lock();
        $s=$this->pdo->prepare("INSERT INTO retention_runs(status,batch_limit,removed,backlog_lower_bound,oldest_received_at,sample_capped,duration_ms,total_removed) VALUES (:status,:limit,:removed,:remaining,:oldest,:more,:duration,COALESCE((SELECT total_removed FROM retention_runs ORDER BY id DESC LIMIT 1),0)+COALESCE(CAST(:increment AS bigint),0))");
        $s->execute(['status'=>$status,'limit'=>$limit,'removed'=>$removed,'remaining'=>$remaining,'oldest'=>$oldest,'more'=>$more?'true':'false','duration'=>$duration,'increment'=>$removed]);
        $this->pdo->exec('DELETE FROM retention_runs WHERE id IN (SELECT id FROM retention_runs WHERE id < (SELECT id FROM retention_runs ORDER BY id DESC OFFSET 999 LIMIT 1) ORDER BY id LIMIT 1000)');
    }
    public function failure(int $limit): void {
        if (!TransactionManager::usable($this->pdo)) return;
        try {(new TransactionManager($this->pdo))->run(function () use ($limit) {
            $this->pdo->exec('SET LOCAL statement_timeout=1000');
            $this->pdo->exec('SET LOCAL lock_timeout=500');
            $this->record('failed',$limit,null,null,null,false);
        });} catch (\Throwable) {error_log('CajeerLogs retention history unavailable');}
    }
    public function history(int $limit=50): array {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('History limit must be 1..100.');
        $s=$this->pdo->prepare('SELECT id,finished_at,status,batch_limit,removed,backlog_lower_bound,oldest_received_at,sample_capped,duration_ms,total_removed FROM retention_runs ORDER BY id DESC LIMIT :limit');
        $s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();return $s->fetchAll();
    }
    public function run(int $limit=1000): int {
        try {return (new TransactionManager($this->pdo))->run(fn()=> $this->prune($limit), maxAttempts: 3);}
        catch (\Throwable $e) {if ($limit>=1 && $limit<=10000) $this->failure($limit);throw $e;}
    }
    /** Independent transactions; pause outside locks and stop after a short batch. */
    public function cycle(int $limit,int $batches,int $pauseMs,int $budgetMs): array {
        if ($limit<1||$limit>10000||$batches<1||$batches>100||$pauseMs<0||$pauseMs>5000||$budgetMs<100||$budgetMs>300000) throw new \InvalidArgumentException('Invalid retention cycle limits.');
        $start=hrtime(true);$removed=0;$done=0;$reason='batch_limit';
        for ($i=0;$i<$batches;$i++) {
            if ($i>0 && (hrtime(true)-$start)/1e6+$pauseMs>=$budgetMs) {$reason='time_budget';break;}
            if ($i>0 && $pauseMs>0) usleep($pauseMs*1000);
            $remaining=max(100,$budgetMs-(int)((hrtime(true)-$start)/1e6));
            $n=(new self($this->pdo,min($this->timeoutMs,$remaining)))->run($limit);$removed+=$n;$done++;
            if ($n<$limit) {$reason='short_batch';break;}
        }
        return ['removed'=>$removed,'batches'=>$done,'stop_reason'=>$reason,'duration_ms'=>(int)((hrtime(true)-$start)/1e6)];
    }
}
