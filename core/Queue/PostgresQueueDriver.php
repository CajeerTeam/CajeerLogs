<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\TransactionManager;

use PDO;
use Cajeer\Logs\Database\DatabaseFailure;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Log\IngestEvent;

/** PostgreSQL durable queue; all payloads are already validated and redacted. */
final class PostgresQueueDriver implements QueueDriverInterface
{
    public function __construct(private readonly PDO $pdo, private readonly int $maxJobs=1000) {
        if ($maxJobs<1 || $maxJobs>100000) { throw new \InvalidArgumentException('Invalid QUEUE_MAX_JOBS.'); }
    }
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql);$s->execute($params);return $s; }
    public static function encodeEvents(array $events): array {
        return array_map(static fn(IngestEvent $event): array => get_object_vars($event),$events);
    }
    public static function decodeEvents(array $payload): array {
        return array_map(static fn(\stdClass $e): IngestEvent => new IngestEvent($e->source,$e->level,$e->message,$e->context,$e->timestamp,$e->service,$e->host,$e->traceId,$e->requestId,$e->tags),$payload);
    }
    /** Caller holds the authenticated token lock; enqueue is serialized for a hard global capacity bound. */
    public function enqueue(string $token,string $project,string $key,string $hash,array $events,array $sources): array {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Enqueue requires a transaction.'); }
        $row=$this->sql("SELECT id,status,request_hash FROM jobs WHERE queue='ingest-v1' AND token_id=:token AND key_hash=:key",['token'=>$token,'key'=>$key])->fetch();
        if ($row) {
            if (!hash_equals($row['request_hash'],$hash)) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key was used for a different request.'); }
            return ['job_id'=>(string)$row['id'],'status'=>$row['status'],'replayed'=>true];
        }
        $id=$this->push(new QueuedJob('ingest',['token'=>$token,'project'=>$project,'key'=>$key,'hash'=>$hash,'events'=>self::encodeEvents($events),'sources'=>$sources]));
        return ['job_id'=>$id,'status'=>'pending','replayed'=>false];
    }
    public function push(QueuedJob $job): string {
        if (!$this->pdo->inTransaction() || $job->name!=='ingest') { throw new \LogicException('Only transactional ingest jobs are supported.'); }
        $p=$job->payload;
        $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:enqueue:' || current_schema(),0))")->closeCursor();
        if ((int)$this->pdo->query("SELECT count(*) FROM jobs WHERE queue='ingest-v1' AND status IN ('pending','running','dead')")->fetchColumn()>=$this->maxJobs) {
            throw new HttpException(503,'queue_full','Ingest queue capacity reached.',['Retry-After'=>'5']);
        }
        $body=json_encode(['events'=>$p['events'],'sources'=>(object)$p['sources']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        // Bound stored normalized data, including JSON encoding overhead.
        if (strlen($body)>2097152) { throw new HttpException(413,'payload_too_large','Normalized job exceeds 2 MiB.'); }
        return (string)$this->sql("INSERT INTO jobs(queue,name,payload,token_id,project_id,key_hash,request_hash) VALUES ('ingest-v1','ingest',CAST(:payload AS jsonb),:token,:project,:key,:hash) RETURNING id",['payload'=>$body,'token'=>$p['token'],'project'=>$p['project'],'key'=>$p['key'],'hash'=>$p['hash']])->fetchColumn();
    }
    public function hasKey(string $token,string $key): bool {
        return (bool)$this->sql("SELECT 1 FROM jobs WHERE queue='ingest-v1' AND token_id=:token AND key_hash=:key",['token'=>$token,'key'=>$key])->fetchColumn();
    }
    public function status(string $id,string $token): ?array {
        $row=$this->sql("SELECT id,status,attempts,max_attempts,created_at,available_at,completed_at,result,last_error FROM jobs WHERE queue='ingest-v1' AND id=:id AND token_id=:token",['id'=>$id,'token'=>$token])->fetch();
        if (!$row) { return null; }
        $row['id']=(string)$row['id'];$row['result']=$row['result']===null?null:json_decode($row['result'],true,32,JSON_THROW_ON_ERROR);return $row;
    }
    /** Claim one job, including an expired lease. Claim/attempt history commit before processing. */
    public function claim(): ?array {
        return (new TransactionManager($this->pdo))->run(function () {
            $row=$this->pdo->query("SELECT id,status,attempts,max_attempts FROM jobs WHERE queue='ingest-v1' AND ((status='pending' AND available_at<=statement_timestamp()) OR (status='running' AND lease_until<=statement_timestamp())) ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if (!$row) { return null; }
            if ($row['status']==='running') {
                $this->sql("UPDATE job_attempts SET outcome='expired',finished_at=clock_timestamp(),error='lease_expired' WHERE job_id=:id AND attempt=:attempt AND outcome='running'",['id'=>$row['id'],'attempt'=>$row['attempts']]);
            }
            if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
                $this->sql("UPDATE jobs SET status='dead',lease_id=NULL,lease_until=NULL,reserved_at=NULL,completed_at=clock_timestamp(),last_error='attempts_exhausted' WHERE id=:id",['id'=>$row['id']]);
                return ['skipped'=>true];
            }
            $claimed=$this->sql("UPDATE jobs SET status='running',attempts=attempts+1,reserved_at=clock_timestamp(),lease_until=clock_timestamp()+interval '2 minutes',lease_id=gen_random_uuid() WHERE id=:id RETURNING id,lease_id,attempts",['id'=>$row['id']])->fetch();
            $this->sql('INSERT INTO job_attempts(job_id,attempt) VALUES (:id,:attempt)',['id'=>$claimed['id'],'attempt'=>$claimed['attempts']]);
            return $claimed;
        }, maxAttempts: 3);
    }
    /** Row lock fences stale workers; handler and completion share a single transaction. */
    public function process(array $claim): string {
        try {
            return (new TransactionManager($this->pdo))->run(function () use ($claim) {
                $row=$this->sql("SELECT * FROM jobs WHERE id=:id AND queue='ingest-v1' AND status='running' AND lease_id=:lease AND lease_until>clock_timestamp() FOR UPDATE",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetch();
                if (!$row) { return 'stale'; }
                $payload=json_decode($row['payload'],false,64,JSON_THROW_ON_ERROR);
                $events=self::decodeEvents($payload->events);
                $ids=(new \Cajeer\Logs\Log\LogRepository($this->pdo))->storeBatch($events,$row['project_id'],(array)$payload->sources,$row['token_id']);
                (new \Cajeer\Logs\Integration\EventApiDispatcher($this->pdo))->publishCommittedLogs($row['project_id'],$ids);
                $this->sql("UPDATE jobs SET status='completed',payload='{}'::jsonb,result=CAST(:result AS jsonb),completed_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error=NULL WHERE id=:id",['id'=>$row['id'],'result'=>json_encode(['event_ids'=>$ids,'stored'=>count($ids)],JSON_THROW_ON_ERROR)]);
                $this->sql("UPDATE job_attempts SET outcome='completed',finished_at=clock_timestamp() WHERE job_id=:id AND attempt=:attempt",['id'=>$row['id'],'attempt'=>$row['attempts']]);
                return 'completed';
            }, maxAttempts: 3);
        } catch (\Throwable $e) {
            // A broken session or uncertain COMMIT must not be converted into an ordinary retry.
            // A fresh worker observes the committed state or recovers the lease after timeout.
            if (DatabaseFailure::reconnectable($e) || !TransactionManager::usable($this->pdo)) {
                throw $e;
            }
            // Do not persist exception messages, which may contain raw event fields or secrets.
            return $this->fail($claim,$e instanceof \PDOException?'database_error':'processing_error');
        }
    }
    public static function backoff(int $attempt): int { return min(3600, 5 * (2 ** min(10,max(0,$attempt-1)))); }
    private function fail(array $claim,string $error): string {
        return (new TransactionManager($this->pdo))->run(function () use ($claim,$error) {
            $row=$this->sql("SELECT attempts,max_attempts FROM jobs WHERE id=:id AND status='running' AND lease_id=:lease FOR UPDATE",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetch();
            if (!$row) { return 'stale'; }
            $dead=(int)$row['attempts'] >= (int)$row['max_attempts'];$outcome=$dead?'dead':'retry';
            $this->sql("UPDATE jobs SET status=:status,available_at=clock_timestamp()+CAST(:delay AS integer)*interval '1 second',completed_at=CASE WHEN CAST(:dead AS boolean) THEN clock_timestamp() ELSE NULL END,lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error=:error WHERE id=:id",['status'=>$dead?'dead':'pending','delay'=>self::backoff((int)$row['attempts']),'dead'=>$dead?'true':'false','error'=>$error,'id'=>$claim['id']]);
            $this->sql('UPDATE job_attempts SET outcome=:outcome,error=:error,finished_at=clock_timestamp() WHERE job_id=:id AND attempt=:attempt',['outcome'=>$outcome,'error'=>$error,'id'=>$claim['id'],'attempt'=>$row['attempts']]);
            return $outcome;
        }, maxAttempts: 3);
    }
    public function retry(string $id): bool {
        return $this->sql("UPDATE jobs SET status='pending',available_at=clock_timestamp(),completed_at=NULL,max_attempts=LEAST(attempts+8,100),last_error=NULL WHERE id=:id AND queue='ingest-v1' AND status='dead' AND attempts<100",['id'=>$id])->rowCount()===1;
    }
    public function discard(string $id): bool {
        return $this->sql("UPDATE jobs SET status='cancelled',payload='{}'::jsonb,completed_at=clock_timestamp(),last_error='discarded_by_operator' WHERE id=:id AND queue='ingest-v1' AND status='dead'",['id'=>$id])->rowCount()===1;
    }
    public function history(string $id): array { return $this->sql("SELECT a.* FROM job_attempts a JOIN jobs j ON j.id=a.job_id WHERE j.queue='ingest-v1' AND j.id=:id ORDER BY a.attempt DESC LIMIT 100",['id'=>$id])->fetchAll(); }
    public function list(string $status): array {
        return $this->sql("SELECT id,project_id,status,attempts,max_attempts,created_at,available_at,completed_at,last_error FROM jobs WHERE queue='ingest-v1' AND status=:status ORDER BY id LIMIT 100",['status'=>$status])->fetchAll();
    }
    public function counts(): array {
        $counts=array_fill_keys(['pending','running','completed','dead','cancelled'],0);
        foreach ($this->pdo->query("SELECT status,count(*) AS count FROM jobs WHERE queue='ingest-v1' GROUP BY status")->fetchAll() as $row) { $counts[$row['status']]=(int)$row['count']; }
        return $counts;
    }
    public function cleanup(): int {
        return (int)$this->pdo->exec("DELETE FROM jobs WHERE id IN (SELECT id FROM jobs WHERE queue='ingest-v1' AND status IN ('completed','cancelled') AND completed_at<statement_timestamp()-interval '7 days' ORDER BY completed_at,id LIMIT 1000 FOR UPDATE SKIP LOCKED)");
    }
}
