<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Globally consistent, durable admission policy. All decisions hold the same row lock as the jobs accounting trigger. */
final class BackpressurePolicy
{
    public readonly int $maxJobs;
    public readonly int $maxEvents;
    public readonly int $maxBytes;
    public readonly int $highPercent;
    public readonly int $lowPercent;

    public function __construct(int $maxJobs=1000, int $maxEvents=100000, int $maxBytes=536870912, int $highPercent=85, int $lowPercent=60)
    {
        if ($maxJobs<1 || $maxJobs>100000 || $maxEvents<0 || $maxEvents>1000000000 ||
            $maxBytes<0 || $maxBytes>1099511627776 || $highPercent<1 || $highPercent>100 ||
            $lowPercent<0 || $lowPercent>99 || $lowPercent >= $highPercent) {
            throw new \InvalidArgumentException('Invalid queue backpressure settings.');
        }
        $this->maxJobs=$maxJobs;
        $this->maxEvents=$maxEvents;
        $this->maxBytes=$maxBytes;
        $this->highPercent=$highPercent;
        $this->lowPercent=$lowPercent;
    }

    public static function fromConfig(ConfigRepository $config): self
    {
        $int=static function (mixed $value,string $key): int {
            if (!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value))) {
                throw new \InvalidArgumentException('Invalid '.$key.'.');
            }
            $number=filter_var($value,FILTER_VALIDATE_INT);
            if ($number===false) throw new \InvalidArgumentException('Invalid '.$key.'.');
            return (int)$number;
        };
        return new self(
            $int($config->get('queue.max_jobs'),'QUEUE_MAX_JOBS'),
            $int($config->get('queue.max_events'),'QUEUE_MAX_EVENTS'),
            $int($config->get('queue.max_bytes'),'QUEUE_MAX_BYTES'),
            $int($config->get('queue.high_watermark_percent'),'QUEUE_HIGH_WATERMARK_PERCENT'),
            $int($config->get('queue.low_watermark_percent'),'QUEUE_LOW_WATERMARK_PERCENT')
        );
    }

    public function limits(): array
    {
        return ['max_jobs'=>$this->maxJobs,'max_events'=>$this->maxEvents,'max_bytes'=>$this->maxBytes,
            'high_watermark_percent'=>$this->highPercent,'low_watermark_percent'=>$this->lowPercent];
    }

    private function resources(): array
    {
        return ['jobs'=>$this->maxJobs,'events'=>$this->maxEvents,'bytes'=>$this->maxBytes];
    }

    /** No floating point thresholds: compares integer counters to integer high/low watermarks. */
    private static function crossed(int $current,int $limit,int $percent): bool
    {
        return $limit>0 && $current>=intdiv($limit*$percent+99,100);
    }
    private static function drained(int $current,int $limit,int $percent): bool
    {
        return $limit===0 || $current<=intdiv($limit*$percent,100);
    }

    /**
     * Reserve admission while caller holds a transaction and the authenticated token lock.
     * No capacity is decremented here: jobs_account_delta updates all three dimensions on
     * INSERT/complete/discard in this same transaction. Returning a denial (not throwing)
     * lets HTTP persist the overload latch/rejection telemetry without quota consumption.
     * Direct non-HTTP callers can throw the returned exception and roll back.
     */
    public function admit(PDO $pdo,int $events,string $body,?int $measuredBytes=null): ?HttpException
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Queue admission requires a transaction.');
        if ($events<1 || $events>100) throw new \InvalidArgumentException('Invalid queued event count.');
        // Perform the expensive JSONB size conversion before taking the shared
        // admission row lock; it is deterministic for this immutable payload.
        $bytes=0;
        if ($this->maxBytes>0) {
            if ($measuredBytes!==null) {
                if ($measuredBytes<0) throw new \InvalidArgumentException('Invalid measured payload bytes.');
                $bytes=$measuredBytes;
            } else {
                $measure=$pdo->prepare('SELECT octet_length(CAST(:body AS jsonb)::text)');
                $measure->execute(['body'=>$body]);
                $bytes=(int)$measure->fetchColumn();
            }
        }
        // Same lock order as reconcile(): jobs table first, then the accounting row.
        $pdo->exec('LOCK TABLE jobs IN ROW EXCLUSIVE MODE');
        $stmt=$pdo->query("SELECT active_jobs,active_events,active_bytes,admission_paused::integer AS admission_paused FROM queue_accounting WHERE queue='ingest-v1' FOR UPDATE");
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \RuntimeException('Queue accounting is missing.');
        $current=['jobs'=>(int)$row['active_jobs'],'events'=>(int)$row['active_events'],'bytes'=>(int)$row['active_bytes']];
        $limits=$this->resources();
        $paused=(bool)$row['admission_paused'];
        $isHigh=false;$isLow=true;
        foreach ($limits as $name=>$limit) {
            $isHigh=$isHigh||self::crossed($current[$name],$limit,$this->highPercent);
            $isLow=$isLow&&self::drained($current[$name],$limit,$this->lowPercent);
        }
        if ($paused && $isLow) $paused=false;
        elseif (!$paused && $isHigh) $paused=true;

        $incoming=['jobs'=>1,'events'=>$events,'bytes'=>$bytes];
        $reason=null;
        foreach ($limits as $name=>$limit) {
            if ($limit>0 && $incoming[$name]>$limit-$current[$name]) { $reason=$name; break; }
        }
        if ($reason===null && $paused) $reason='paused';
        if ($reason!==null) {
            // Caller returns the exception and commits this small state change.
            $column=match($reason) {'jobs'=>'rejected_jobs','events'=>'rejected_events','bytes'=>'rejected_bytes',default=>'rejected_paused'};
            $pdo->exec('UPDATE queue_accounting SET admission_paused='.($paused?'true':'false').", $column=$column+1 WHERE queue='ingest-v1'");
            return new HttpException(503,'queue_full','Ingest queue is at capacity ('.$reason.').',['Retry-After'=>'5']);
        }
        if ((bool)$row['admission_paused'] !== $paused) {
            $pdo->exec("UPDATE queue_accounting SET admission_paused=".($paused?'true':'false')." WHERE queue='ingest-v1'");
        }
        return null;
    }
    /** Called after the INSERT trigger has accounted for the accepted job. */
    public function accepted(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Queue acceptance requires a transaction.');
        $row=$pdo->query("SELECT active_jobs,active_events,active_bytes,admission_paused::integer AS admission_paused FROM queue_accounting WHERE queue='ingest-v1' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \RuntimeException('Queue accounting is missing.');
        foreach ($this->resources() as $name=>$limit) {
            if (self::crossed((int)$row['active_'.$name],$limit,$this->highPercent)) {
                if (!(bool)$row['admission_paused']) $pdo->exec("UPDATE queue_accounting SET admission_paused=true WHERE queue='ingest-v1'");
                break;
            }
        }
    }
}
