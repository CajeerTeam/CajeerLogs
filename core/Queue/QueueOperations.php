<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Durable cluster-wide dispatch control. Admission remains independent of dispatch.
 * A SHARE lock per claim fences pause against concurrent multi-process claims:
 * after pause commits, no new lease can be issued until resume commits. */
final class QueueOperations
{
    public function __construct(private readonly PDO $pdo) {}

    public static function reason(mixed $value): ?string
    {
        if ($value===null || $value==='') return null;
        if (!is_string($value) || strlen($value)>200 || preg_match('/[\x00-\x1f\x7f]/', $value))
            throw new \InvalidArgumentException('Invalid queue dispatch reason.');
        return $value;
    }

    public static function duration(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value)))
            throw new \InvalidArgumentException('Invalid drain timeout.');
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>3600]]);
        if ($n===false) throw new \InvalidArgumentException('Drain timeout must be 0..3600 seconds.');
        return $n;
    }

    /** Must be called from inside the exact transaction that claims jobs.
     * This lock prevents a pause from racing with an already selected project. */
    public static function canClaim(PDO $pdo): bool
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Dispatch check requires a claim transaction.');
        $row=$pdo->query("SELECT paused::integer FROM queue_dispatch_control WHERE queue='ingest-v1' FOR SHARE")->fetchColumn();
        if ($row===false) throw new \RuntimeException('Queue dispatch state is missing.');
        return (int)$row===0;
    }

    /** No job payload or credential is exposed in this operator snapshot. */
    public function status(): array
    {
        $row=$this->pdo->query("SELECT paused::integer AS paused,generation,reason,changed_by,changed_at,\n            GREATEST(0,extract(epoch from clock_timestamp()-changed_at)) AS age_seconds\n            FROM queue_dispatch_control WHERE queue='ingest-v1'")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Queue dispatch state is missing.');
        $counts=$this->pdo->query("SELECT count(*) FILTER (WHERE status='running' AND lease_until>clock_timestamp()) AS live,\n            count(*) FILTER (WHERE status='running' AND lease_until<=clock_timestamp()) AS expired,\n            count(*) FILTER (WHERE status='pending' AND available_at<=clock_timestamp()) AS ready\n            FROM jobs WHERE queue='ingest-v1' AND status IN ('pending','running')")->fetch(PDO::FETCH_ASSOC);
        return ['paused'=>(bool)(int)$row['paused'],'generation'=>(int)$row['generation'],
            'reason'=>$row['reason'],'changed_by'=>$row['changed_by'],'changed_at'=>$row['changed_at'],
            'age_seconds'=>(float)$row['age_seconds'],'live_running'=>(int)$counts['live'],
            'expired_leases'=>(int)$counts['expired'],'ready_jobs'=>(int)$counts['ready']];
    }

    /** Return the committed state. Idempotent requests do not produce another audit event. */
    public function setPaused(bool $pause,?string $reason=null,?string $actor=null,?string $requestId=null): array
    {
        $reason=self::reason($reason);
        if ($actor!==null && !QueueFairness::validProject($actor)) throw new \InvalidArgumentException('Invalid operator identity.');
        if ($requestId!==null && (strlen($requestId)>128 || preg_match('/[^a-zA-Z0-9._:-]/',$requestId))) throw new \InvalidArgumentException('Invalid request ID.');
        $change=function() use($pause,$reason,$actor,$requestId): void {
            $q=$this->pdo->query("SELECT paused::integer AS paused FROM queue_dispatch_control WHERE queue='ingest-v1' FOR UPDATE");
            $old=$q->fetchColumn();
            if ($old===false) throw new \RuntimeException('Queue dispatch state is missing.');
            if ((bool)(int)$old===$pause) return;
            $s=$this->pdo->prepare("UPDATE queue_dispatch_control SET paused=:paused,generation=generation+1,reason=:reason,changed_at=clock_timestamp(),changed_by=CAST(:actor AS uuid) WHERE queue='ingest-v1'");
            $s->execute(['paused'=>$pause?'true':'false','reason'=>$pause?$reason:null,'actor'=>$actor]);
            (new AuditLogger($this->pdo))->record($pause?'queue.dispatch_paused':'queue.dispatch_resumed',
                ['queue'=>'ingest-v1','reason'=>$pause?$reason:null,'request_id'=>$requestId],$actor,'ingest-v1','success',$actor===null?'system':'user');
        };
        if ($this->pdo->inTransaction()) $change();
        else (new TransactionManager($this->pdo))->run($change,maxAttempts:3);
        return $this->status();
    }

    /** Explicitly pause and wait for live leases to finish. Expired leases are
     * reported but intentionally not consumed while paused; resume to recover. */
    public function drain(int $timeoutSeconds,?string $reason=null): array
    {
        $timeoutSeconds=self::duration($timeoutSeconds);
        $this->setPaused(true,$reason);
        $deadline=hrtime(true)+$timeoutSeconds*1000000000;
        do {
            $status=$this->status();
            if ($status['live_running']===0) return ['drained'=>true]+$status;
            if (hrtime(true)>=$deadline) return ['drained'=>false]+$status;
            usleep(250000);
        } while (true);
    }
}
