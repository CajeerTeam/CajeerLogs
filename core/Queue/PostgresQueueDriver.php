<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Database\TransactionManager;

use PDO;
use Cajeer\Logs\Database\DatabaseFailure;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Log\{IngestEvent,IngestBatch};

/** PostgreSQL durable queue; all payloads are already validated and redacted. */
final class PostgresQueueDriver implements QueueDriverInterface
{
    public function __construct(private readonly PDO $pdo, private readonly int $maxJobs=1000, private readonly ?WorkerPolicy $workerPolicy=null, private readonly RetryPolicy $retryPolicy=new RetryPolicy(), private readonly ?BackpressurePolicy $backpressurePolicy=null) {
        if ($maxJobs<1 || $maxJobs>100000) { throw new \InvalidArgumentException('Invalid QUEUE_MAX_JOBS.'); }
    }
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql);$s->execute($params);return $s; }
    public static function encodeEvents(array $events): array {
        return array_map(static fn(IngestEvent $event): array => get_object_vars($event),$events);
    }
    public static function decodeEvents(array $payload): array {
        return IngestBatch::decodeEvents($payload);
    }
    /** Caller holds the authenticated token lock; enqueue reserves capacity using the transactional accounting row. */
    public function enqueue(string $token,string $project,string $key,string $hash,array $events,array $sources,?callable $admit=null,?string $digest=null,int $retention=\Cajeer\Logs\Log\DeliveryRepository::RETENTION_SECONDS): array|HttpException {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Enqueue requires a transaction.'); }
        $row=$this->sql("SELECT id,status,request_hash FROM jobs WHERE queue='ingest-v1' AND token_id=:token AND project_id=:project AND key_hash=:key",['token'=>$token,'project'=>$project,'key'=>$key])->fetch();
        if ($row) {
            if (!hash_equals($row['request_hash'],$hash)) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key was used for a different request.'); }
            return ['job_id'=>(string)$row['id'],'status'=>$row['status'],'replayed'=>true];
        }
        $events=IngestBatch::normalize($events,IngestBatch::JOB_BYTES);
        $sources=IngestBatch::sourceMap($events,$sources);
        $this->assertEnqueueScope($token,$project,$sources);
        $events=(new \Cajeer\Logs\Log\RedactionPolicies($this->pdo))->apply($events,$project,$sources);
        $body=IngestBatch::encodeJob($events,$sources);
        // A replay has already returned above. Check the exact shared queue budgets
        // BEFORE project/source quotas: a capacity denial must not consume quota.
        // The row lock is held until the caller commits the durable job or denial.
        if (($denied=$this->backpressure()->admit($this->pdo,count($events),$body))!==null) return $denied;
        if ($admit!==null && ($denied=$admit())!==null) {
            if (!$denied instanceof HttpException) throw new \LogicException('Invalid admission result.');
            return $denied;
        }
        $id=$this->insertJob($token,$project,$key,$hash,$body);
        if ($digest!==null) $this->sql("UPDATE jobs SET delivery_digest=:digest,delivery_count=:count,delivery_retention_seconds=:ttl WHERE id=:id",['digest'=>$digest,'count'=>count($events),'ttl'=>\Cajeer\Logs\Log\DeliveryRepository::retention($retention),'id'=>$id]);
        return ['job_id'=>$id,'status'=>'pending','replayed'=>false];
    }
    public function push(QueuedJob $job): string {
        if (!$this->pdo->inTransaction() || $job->name!=='ingest') { throw new \LogicException('Only transactional ingest jobs are supported.'); }
        $p=$job->payload;
        $raw=json_encode(['events'=>$p['events'],'sources'=>(object)$p['sources']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        [$events,$sources]=IngestBatch::decodeJob($raw);
        $this->assertEnqueueScope($p['token'],$p['project'],$sources);
        $events=(new \Cajeer\Logs\Log\RedactionPolicies($this->pdo))->apply($events,$p['project'],$sources);
        $body=IngestBatch::encodeJob($events,$sources);
        if (($denied=$this->backpressure()->admit($this->pdo,count($events),$body))!==null) throw $denied;
        return $this->insertJob($p['token'],$p['project'],$p['key'],$p['hash'],$body);
    }
    /** Defense in depth for direct repository callers. Lock the token before the
     * capacity lock, and verify the exact source map against registered sources. */
    private function assertEnqueueScope(string $token,string $project,array $sources): void {
        $ok=$this->sql(<<<'SQL'
            WITH locked AS MATERIALIZED (
                SELECT id,project_id,source_id,scopes,revoked_at,expires_at,service_account_id
                FROM api_tokens WHERE id=CAST(:token AS uuid) FOR UPDATE
            ), active AS MATERIALIZED (
                SELECT t.* FROM locked t WHERE t.revoked_at IS NULL
                AND (t.expires_at IS NULL OR t.expires_at>clock_timestamp())
                AND (t.service_account_id IS NULL OR EXISTS (SELECT 1 FROM service_accounts a
                    WHERE a.id=t.service_account_id AND a.project_id=t.project_id AND a.disabled_at IS NULL))
                ) SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM active) THEN 'unauthorized'
            WHEN EXISTS (SELECT 1 FROM active t WHERE t.project_id=CAST(:project AS uuid)
                AND jsonb_typeof(t.scopes)='array' AND t.scopes @> '["logs:write"]'::jsonb
                AND t.scopes <@ '["logs:read","logs:write"]'::jsonb
                AND jsonb_array_length(CASE WHEN jsonb_typeof(t.scopes)='array' THEN t.scopes ELSE '[]'::jsonb END)=
                    CASE WHEN t.scopes @> '["logs:read"]'::jsonb THEN 2 ELSE 1 END
                AND NOT EXISTS (SELECT 1 FROM jsonb_each_text(CAST(:sources AS jsonb)) m(slug,source_id)
                    WHERE NOT EXISTS (SELECT 1 FROM sources s WHERE s.id=CAST(m.source_id AS uuid)
                        AND s.project_id=t.project_id AND s.slug=m.slug AND (t.source_id IS NULL OR t.source_id=s.id)))
            ) THEN 'ok' ELSE 'forbidden' END
            SQL,['token'=>$token,'project'=>$project,'sources'=>json_encode((object)$sources,JSON_THROW_ON_ERROR)])->fetchColumn();
        if ($ok==='unauthorized') \Cajeer\Logs\Security\Identity::unauthorized();
        if ($ok!=='ok') \Cajeer\Logs\Security\Identity::forbidden();
    }
    private function backpressure(): BackpressurePolicy {
        return $this->backpressurePolicy ?? new BackpressurePolicy($this->maxJobs);
    }
    private function insertJob(string $token,string $project,string $key,string $hash,string $body): string {
        // JSONB expands exponent notation and inserts whitespace. Never acknowledge a
        // payload that the bounded worker cannot read back from PostgreSQL.
        $id=$this->sql("INSERT INTO jobs(queue,name,payload,token_id,project_id,key_hash,request_hash,max_attempts)
            SELECT 'ingest-v1','ingest',b.body,CAST(:token AS uuid),CAST(:project AS uuid),CAST(:key AS text),CAST(:hash AS text),CAST(:max_attempts AS integer) FROM (SELECT CAST(:payload AS jsonb) AS body) b
            WHERE octet_length(b.body::text)<=CAST(:limit AS integer) RETURNING id",['payload'=>$body,'token'=>$token,'project'=>$project,'key'=>$key,'hash'=>$hash,'limit'=>IngestBatch::JOB_DECODE_BYTES,'max_attempts'=>$this->retryPolicy->get('max_attempts')])->fetchColumn();
        if ($id===false) throw new HttpException(413,'payload_too_large','Stored job exceeds decoding limit.');
        $this->backpressure()->accepted($this->pdo);
        return (string)$id;
    }
    public function hasKey(string $token,string $key): bool {
        return (bool)$this->sql("SELECT 1 FROM jobs WHERE queue='ingest-v1' AND token_id=:token AND key_hash=:key",['token'=>$token,'key'=>$key])->fetchColumn();
    }
    public function status(string $id,string $token,string $project): ?array {
        $row=$this->sql("SELECT id,status,attempts,max_attempts,created_at,available_at,completed_at,result,last_error FROM jobs WHERE queue='ingest-v1' AND id=:id AND token_id=:token AND project_id=:project",['id'=>$id,'token'=>$token,'project'=>$project])->fetch();
        if (!$row) { return null; }
        $row['id']=(string)$row['id'];$row['result']=$row['result']===null?null:json_decode($row['result'],true,32,JSON_THROW_ON_ERROR);return $row;
    }
    private function workerTimeout(bool $processing): void {
        if ($this->workerPolicy===null) return;
        $ms=$processing?$this->workerPolicy->get('process_timeout_seconds')*1000:$this->workerPolicy->get('claim_timeout_ms');
        // PostgreSQL 17 transaction_timeout bounds the entire transaction, not only a statement.
        $this->pdo->exec("SET LOCAL transaction_timeout=$ms; SET LOCAL statement_timeout=$ms; SET LOCAL lock_timeout=".min($ms,5000));
    }
    /** Claim one job, including an expired lease. Claim/attempt history commit before processing. */
    public function claim(): ?array {
        return (new TransactionManager($this->pdo))->run(function () {
            $this->workerTimeout(false);
            $row=$this->pdo->query("SELECT id,status,attempts,max_attempts FROM jobs WHERE queue='ingest-v1' AND status='running' AND lease_until<=statement_timestamp() ORDER BY lease_until,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if (!$row) $row=$this->pdo->query("SELECT id,status,attempts,max_attempts FROM jobs WHERE queue='ingest-v1' AND status='pending' AND available_at<=statement_timestamp() ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if (!$row) { return null; }
            $expired=$row['status']==='running';
            if ($expired) {
                if ($this->sql("UPDATE job_attempts SET outcome='expired',finished_at=clock_timestamp(),error='lease_expired' WHERE job_id=:id AND attempt=:attempt AND outcome='running'",['id'=>$row['id'],'attempt'=>$row['attempts']])->rowCount()!==1) throw new \RuntimeException('Missing running job attempt; roll back lease recovery.');
            }
            if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
                $this->sql("UPDATE jobs SET status='dead',lease_id=NULL,lease_until=NULL,reserved_at=NULL,completed_at=clock_timestamp(),last_error='attempts_exhausted' WHERE id=:id",['id'=>$row['id']]);
                return ['skipped'=>true];
            }
            if ($expired) {
                // Use the original lease deadline, not the recovery scan time. An old
                // expired lease can be reclaimed immediately; recent crashes are spread out.
                $delay=$this->retryPolicy->delay((int)$row['attempts']);
                $deferred=$this->sql("UPDATE jobs SET status='pending',available_at=lease_until+CAST(:delay AS integer)*interval '1 second',lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error='lease_expired' WHERE id=:id AND lease_until+CAST(:delay_check AS integer)*interval '1 second'>clock_timestamp()",['id'=>$row['id'],'delay'=>$delay,'delay_check'=>$delay])->rowCount();
                if ($deferred===1) return ['skipped'=>true];
            }
            $claimed=$this->sql("UPDATE jobs SET status='running',attempts=attempts+1,reserved_at=clock_timestamp(),lease_until=clock_timestamp()+CAST(:seconds AS integer)*interval '1 second',lease_id=gen_random_uuid() WHERE id=:id RETURNING id,lease_id,attempts,project_id,token_id",['id'=>$row['id'],'seconds'=>$this->workerPolicy?->get('lease_seconds')??120])->fetch();
            $this->sql('INSERT INTO job_attempts(job_id,attempt) VALUES (:id,:attempt)',['id'=>$claimed['id'],'attempt'=>$claimed['attempts']]);
            return $claimed;
        }, maxAttempts: 3);
    }
    /** Claim several compatible ready jobs with one SQL round trip. The first
     * claim retains the established expired-lease recovery ordering. Additional
     * jobs must use the same project and token, so a single authenticated log
     * INSERT can process the group without weakening project boundaries.
     * @return list<array> */
    public function claimBatch(int $max=4): array
    {
        if ($max<1 || $max>16) throw new \InvalidArgumentException('Invalid worker batch size.');
        $first=$this->claim();
        if ($first===null) return [];
        if (isset($first['skipped']) || $max===1) return [$first];
        $more=(new TransactionManager($this->pdo))->run(function() use ($first,$max): array {
            $this->workerTimeout(false);
            $limit=$max-1; // bounded integer; never interpolate user input
            $stmt=$this->sql(<<<SQL
                WITH picked AS MATERIALIZED (
                    SELECT id FROM jobs
                    WHERE queue='ingest-v1' AND status='pending'
                        AND project_id=CAST(:project AS uuid) AND token_id=CAST(:token AS uuid)
                        AND available_at<=statement_timestamp() AND attempts<max_attempts
                    ORDER BY available_at,id LIMIT $limit FOR UPDATE SKIP LOCKED
                ), claimed AS (
                    UPDATE jobs j SET status='running',attempts=attempts+1,
                        reserved_at=clock_timestamp(),
                        lease_until=clock_timestamp()+CAST(:seconds AS integer)*interval '1 second',
                        lease_id=gen_random_uuid()
                    FROM picked WHERE j.id=picked.id
                    RETURNING j.id,j.lease_id,j.attempts,j.project_id,j.token_id
                ), recorded AS (
                    INSERT INTO job_attempts(job_id,attempt)
                    SELECT id,attempts FROM claimed RETURNING job_id
                ) SELECT c.* FROM claimed c JOIN recorded a ON a.job_id=c.id ORDER BY c.id
                SQL,['project'=>$first['project_id'],'token'=>$first['token_id'],
                    'seconds'=>$this->workerPolicy?->get('lease_seconds')??120]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        },maxAttempts:3);
        return [$first,...$more];
    }

    /** Process compatible jobs as one bounded INSERT and one atomic commit.
     * Every job retains its own result, attempt history and webhook delivery.
     * On application/validation errors roll back the WHOLE group and then
     * isolate jobs through the normal single-job path. Never retry a broken
     * connection or an uncertain COMMIT, even if part of the group succeeded. 
     * @param list<array> $claims @return list<string> */
    public function processBatch(array $claims): array
    {
        $count=count($claims);
        if ($count===0 || $count>16) throw new \InvalidArgumentException('Invalid claimed batch.');
        if ($count===1) return [isset($claims[0]['skipped'])?'skipped':$this->process($claims[0])];
        foreach ($claims as $claim) {
            if (!isset($claim['id'],$claim['lease_id'],$claim['project_id'],$claim['token_id']) || isset($claim['skipped'])) {
                throw new \InvalidArgumentException('Incompatible claimed batch.');
            }
        }
        try {
            return (new TransactionManager($this->pdo))->run(function() use ($claims,$count): array {
                $this->workerTimeout(true);
                $project=$claims[0]['project_id'];$token=$claims[0]['token_id'];
                $ordered=$claims;
                usort($ordered,static fn($a,$b)=>((int)$a['id']<=> (int)$b['id']));
                $events=[];$sources=[];$sizes=[];$version=null;
                foreach ($ordered as $claim) {
                    // Locks in ID order prevent deadlocks with other multi-job workers.
                    $row=$this->sql("SELECT id,project_id,token_id,attempts FROM jobs WHERE id=:id AND queue='ingest-v1' AND status='running' AND lease_id=:lease FOR UPDATE",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetch();
                    if (!$row || !$this->leaseLive($claim)) throw new \RuntimeException('Stale job in microbatch.');
                    if ($row['project_id']!==$project || $row['token_id']!==$token) throw new \RuntimeException('Incompatible job in microbatch.');
                    $body=$this->sql('SELECT CASE WHEN payload_bytes<=CAST(:limit AS integer) THEN payload::text END FROM jobs WHERE id=:id',['id'=>$claim['id'],'limit'=>IngestBatch::JOB_DECODE_BYTES])->fetchColumn();
                    if (!is_string($body)) throw new HttpException(413,'payload_too_large','Stored job exceeds decoding limit.');
                    [$part,$mapped,$v]=IngestBatch::decodeJob($body);
                    if ($version!==null && $version!==$v) throw new \RuntimeException('Mixed validation versions in microbatch.');
                    $version=$v;
                    foreach ($mapped as $slug=>$sourceId) {
                        if (isset($sources[$slug]) && $sources[$slug]!==$sourceId) throw new \RuntimeException('Conflicting source map in microbatch.');
                        $sources[$slug]=$sourceId;
                    }
                    $sizes[(string)$claim['id']]=count($part);
                    if (count($events)+count($part)>\Cajeer\Logs\Log\IngestValidator::MAX_BATCH) throw new \RuntimeException('Microbatch exceeds event limit.');
                    array_push($events,...$part);
                }
                // Performs the same bounded normalization, redaction and SQL scope
                // validation as individual jobs. One heavy INSERT for all events.
                \Cajeer\Logs\Log\IngestBatch::checkSize($events,\Cajeer\Logs\Log\IngestBatch::SQL_BYTES);
                $ids=(new \Cajeer\Logs\Log\LogRepository($this->pdo))->storeBatch($events,$project,$sources,$token,$version);
                $offset=0;
                foreach ($ordered as $claim) {
                    $jobId=(string)$claim['id'];
                    $individual=array_slice($ids,$offset,$sizes[$jobId]);$offset+=$sizes[$jobId];
                    // Preserve one outbox publication per original durable job.
                    (new \Cajeer\Logs\Integration\EventApiDispatcher($this->pdo))->publishCommittedLogs($project,$individual);
                    $this->sql("UPDATE jobs SET status='completed',payload='{}'::jsonb,result=CAST(:result AS jsonb),completed_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error=NULL WHERE id=:id",['id'=>$jobId,'result'=>json_encode(['event_ids'=>$individual,'stored'=>count($individual)],JSON_THROW_ON_ERROR)]);
                    if ($this->sql("UPDATE job_attempts SET outcome='completed',finished_at=clock_timestamp() WHERE job_id=:id AND attempt=:attempt AND outcome='running'",['id'=>$jobId,'attempt'=>$claim['attempts']])->rowCount()!==1) throw new \RuntimeException('Missing running job attempt; roll back microbatch.');
                }
                return array_fill(0,$count,'completed');
            },maxAttempts:3);
        } catch (\Throwable $e) {
            // A database/session failure may mean that COMMIT already succeeded.
            if (DatabaseFailure::reconnectable($e) || !TransactionManager::usable($this->pdo) ||
                RetryPolicy::classify($e)['action']==='pause') throw $e;
            // The group transaction was rolled back. Each original claim is still
            // fenced and durable; use normal retry/dead-letter semantics per job.
            return array_map(fn(array $claim): string=>$this->process($claim),$claims);
        }
    }

    /** Row lock fences stale workers; handler and completion share a single transaction. */
    public function process(array $claim): string {
        try {
            return (new TransactionManager($this->pdo))->run(function () use ($claim) {
                $this->workerTimeout(true);
                $row=$this->sql("SELECT id,project_id,token_id,attempts FROM jobs WHERE id=:id AND queue='ingest-v1' AND status='running' AND lease_id=:lease FOR UPDATE",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetch();
                if (!$row || !$this->leaseLive($claim)) { return 'stale'; }
                // Do not transfer an unbounded/corrupted JSONB value into PHP memory.
                $body=$this->sql('SELECT CASE WHEN payload_bytes<=CAST(:limit AS integer) THEN payload::text END FROM jobs WHERE id=:id',['id'=>$row['id'],'limit'=>IngestBatch::JOB_DECODE_BYTES])->fetchColumn();
                if (!is_string($body)) throw new HttpException(413,'payload_too_large','Stored job exceeds decoding limit.');
                [$events,$sources,$version]=IngestBatch::decodeJob($body);
                $ids=(new \Cajeer\Logs\Log\LogRepository($this->pdo))->storeBatch($events,$row['project_id'],$sources,$row['token_id'],$version);
                (new \Cajeer\Logs\Integration\EventApiDispatcher($this->pdo))->publishCommittedLogs($row['project_id'],$ids);
                $this->sql("UPDATE jobs SET status='completed',payload='{}'::jsonb,result=CAST(:result AS jsonb),completed_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error=NULL WHERE id=:id",['id'=>$row['id'],'result'=>json_encode(['event_ids'=>$ids,'stored'=>count($ids)],JSON_THROW_ON_ERROR)]);
                if ($this->sql("UPDATE job_attempts SET outcome='completed',finished_at=clock_timestamp() WHERE job_id=:id AND attempt=:attempt AND outcome='running'",['id'=>$row['id'],'attempt'=>$row['attempts']])->rowCount()!==1) throw new \RuntimeException('Missing running job attempt; roll back completion.');
                return 'completed';
            }, maxAttempts: 3);
        } catch (\Throwable $e) {
            // A broken session or uncertain COMMIT must not be converted into an ordinary retry.
            // A fresh worker observes the committed state or recovers the lease after timeout.
            if (DatabaseFailure::reconnectable($e) || !TransactionManager::usable($this->pdo)) {
                throw $e;
            }
            $decision=RetryPolicy::classify($e);
            if ($decision['action']==='pause') throw $e;
            return $this->fail($claim,$decision['reason'],$decision['action']==='dead');
        }
    }
    public static function backoff(int $attempt): int { return min(3600, 5 * (2 ** min(10,max(0,$attempt-1)))); }
    /** Evaluate expiry after the row lock has been acquired, not before waiting. */
    private function leaseLive(array $claim): bool {
        return $this->sql("SELECT lease_until>clock_timestamp() FROM jobs WHERE id=:id AND status='running' AND lease_id=:lease",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetchColumn()===true;
    }
    private function fail(array $claim,string $error,bool $permanent=false): string {
        return (new TransactionManager($this->pdo))->run(function () use ($claim,$error,$permanent) {
            $this->workerTimeout(false);
            $row=$this->sql("SELECT attempts,max_attempts FROM jobs WHERE id=:id AND status='running' AND lease_id=:lease FOR UPDATE",['id'=>$claim['id'],'lease'=>$claim['lease_id']])->fetch();
            if (!$row || !$this->leaseLive($claim)) { return 'stale'; }
            $dead=$permanent || (int)$row['attempts'] >= (int)$row['max_attempts'];$outcome=$dead?'dead':'retry';
            $this->sql("UPDATE jobs SET status=:status,available_at=clock_timestamp()+CAST(:delay AS integer)*interval '1 second',completed_at=CASE WHEN CAST(:dead AS boolean) THEN clock_timestamp() ELSE NULL END,lease_id=NULL,lease_until=NULL,reserved_at=NULL,last_error=:error WHERE id=:id",['status'=>$dead?'dead':'pending','delay'=>$dead?0:$this->retryPolicy->delay((int)$row['attempts']),'dead'=>$dead?'true':'false','error'=>$error,'id'=>$claim['id']]);
            if ($this->sql("UPDATE job_attempts SET outcome=:outcome,error=:error,finished_at=clock_timestamp() WHERE job_id=:id AND attempt=:attempt AND outcome='running'",['outcome'=>$outcome,'error'=>$error,'id'=>$claim['id'],'attempt'=>$row['attempts']])->rowCount()!==1) throw new \RuntimeException('Missing running job attempt; roll back failure transition.');
            return $outcome;
        }, maxAttempts: 3);
    }
    public function retry(string $id): bool {
        return $this->sql("UPDATE jobs SET status='pending',available_at=clock_timestamp(),completed_at=NULL,max_attempts=LEAST(attempts+CAST(:budget AS integer),100),last_error=NULL WHERE id=:id AND queue='ingest-v1' AND status='dead' AND attempts<100",['id'=>$id,'budget'=>$this->retryPolicy->get('max_attempts')])->rowCount()===1;
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
    public function cleanup(int $floor=0): int {
        if ($floor<0 || $floor>31536000) throw new \InvalidArgumentException('Invalid queue retention floor.');
        return (int)$this->pdo->exec("DELETE FROM jobs WHERE id IN (SELECT id FROM jobs WHERE queue='ingest-v1' AND status IN ('completed','cancelled') AND completed_at<statement_timestamp()-GREATEST(COALESCE(delivery_retention_seconds,604800),$floor)*interval '1 second' ORDER BY completed_at,id LIMIT 1000 FOR UPDATE SKIP LOCKED)");
    }
}
