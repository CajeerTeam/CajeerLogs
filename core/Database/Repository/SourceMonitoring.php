<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use PDO;

/** Admitted/rejected HTTP events. Never interpret this as an active network probe. */
final class SourceMonitoring
{
    public const MAX_HISTORY_DAYS=32;
    public const DEFAULT_IDLE_MINUTES=60;
    public function __construct(private readonly PDO $pdo) {}

    /** Transient batch updates must share the caller's admission transaction. */
    public function count(string $project,string $batch,string $kind): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Admission metrics require transaction.');
        if (!in_array($kind,['accepted','rejected'],true)) throw new \InvalidArgumentException('Invalid source metrics counter.');
        $sql="INSERT INTO source_ingest_minutes(project_id,source_id,minute,$kind)
            SELECT CAST(:project AS uuid),b.id,date_trunc('minute',clock_timestamp()),b.events
            FROM jsonb_to_recordset(CAST(:batch AS jsonb)) AS b(id uuid,events integer)
            ON CONFLICT (project_id,source_id,minute)
            DO UPDATE SET $kind=source_ingest_minutes.$kind+EXCLUDED.$kind";
        $s=$this->pdo->prepare($sql);$s->execute(['project'=>$project,'batch'=>$batch]);
    }

    public static function health(array $source,int $thresholdMinutes,?int $now=null): array
    {
        if ($thresholdMinutes<1 || $thresholdMinutes>10080) throw new \InvalidArgumentException('Invalid idle threshold.');
        $now??=time();
        $seen=isset($source['last_seen_at'])?strtotime((string)$source['last_seen_at']):false;
        $state=match($source['status']) {
            'archived'=>'archived','disabled'=>'disabled',
            'active'=>$seen===false?'never_received':($seen<=$now+300 && $seen>=$now-$thresholdMinutes*60?'receiving':'idle'),
            default=>'unknown',
        };
        return ['state'=>$state,'idle_threshold_minutes'=>$thresholdMinutes,
            'last_admission_at'=>$source['last_seen_at']??null,
            'seconds_since_last_admission'=>$seen===false?null:max(0,$now-$seen),
            'network_availability_checked'=>false];
    }

    /** Bounded SQL aggregates: no raw-event full scan for throughput figures. */
    public function snapshot(string $project,string $source,int $thresholdMinutes=60,array $sourceRow=[]): array
    {
        $q=$this->pdo->prepare(<<<'SQL'
WITH bounds AS (SELECT date_trunc('minute',clock_timestamp()) AS current_minute),
recent AS (
 SELECT m.minute,m.accepted,m.rejected FROM source_ingest_minutes m CROSS JOIN bounds b
 WHERE m.project_id=:project AND m.source_id=:source
   AND m.minute BETWEEN b.current_minute-interval '1439 minutes' AND b.current_minute
)
SELECT COALESCE(sum(accepted),0) AS accepted_24h,
 COALESCE(sum(rejected),0) AS rejected_24h,
 COALESCE(sum(accepted) FILTER (WHERE minute >= (SELECT current_minute FROM bounds)-interval '59 minutes'),0) AS accepted_60m,
 COALESCE(sum(rejected) FILTER (WHERE minute >= (SELECT current_minute FROM bounds)-interval '59 minutes'),0) AS rejected_60m,
 COALESCE(max(accepted),0) AS peak_accepted_per_minute,
 COALESCE(max(rejected),0) AS peak_rejected_per_minute
FROM recent
SQL);
        $q->execute(['project'=>$project,'source'=>$source]);$totals=$q->fetch();
        $history=$this->pdo->prepare(<<<'SQL'
WITH hours AS (
 SELECT generate_series(date_trunc('hour',clock_timestamp(),'UTC')-interval '23 hours',
                        date_trunc('hour',clock_timestamp(),'UTC'),interval '1 hour') AS hour
), counts AS (
 SELECT date_trunc('hour',minute,'UTC') AS hour,sum(accepted) AS accepted,sum(rejected) AS rejected
 FROM source_ingest_minutes
 WHERE project_id=:project AND source_id=:source
   AND minute>=date_trunc('hour',clock_timestamp(),'UTC')-interval '23 hours'
   AND minute<=date_trunc('minute',clock_timestamp())
 GROUP BY 1
)
SELECT to_char(hours.hour AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') AS hour,
       COALESCE(counts.accepted,0) AS accepted,COALESCE(counts.rejected,0) AS rejected
FROM hours LEFT JOIN counts ON hours.hour=counts.hour ORDER BY hours.hour
SQL);
        $history->execute(['project'=>$project,'source'=>$source]);$buckets=[];
        foreach ($history->fetchAll() as $r) $buckets[]=['hour'=>$r['hour'],'accepted'=>(int)$r['accepted'],'rejected'=>(int)$r['rejected']];
        $sample=$this->pdo->prepare(<<<'SQL'
WITH sampled AS (
 SELECT occurred_at,received_at,level FROM log_events
 WHERE project_id=:project AND source_id=:source AND received_at>=clock_timestamp()-interval '24 hours'
 ORDER BY received_at DESC,id DESC LIMIT 2000
)
SELECT count(*) AS sample_size,
 count(*) FILTER (WHERE level IN ('error','critical','alert','emergency')) AS sample_errors,
 count(*) FILTER (WHERE occurred_at>received_at) AS future_timestamps,
 round((avg(greatest(extract(epoch FROM received_at-occurred_at),0))*1000)::numeric,1) AS average_delay_ms,
 round(((percentile_cont(0.95) WITHIN GROUP (ORDER BY greatest(extract(epoch FROM received_at-occurred_at),0)))*1000)::numeric,1) AS p95_delay_ms
FROM sampled
SQL);
        $sample->execute(['project'=>$project,'source'=>$source]);$quality=$sample->fetch();
        $counts=[];
        foreach (['accepted_24h','rejected_24h','accepted_60m','rejected_60m','peak_accepted_per_minute','peak_rejected_per_minute'] as $key) $counts[$key]=(int)$totals[$key];
        $attempts=$counts['accepted_24h']+$counts['rejected_24h'];
        return [
            'tracked_since'=>$sourceRow['ingestion_metrics_since']??null,
            'window_semantics'=>'UTC minute buckets; current and preceding 59/1439 minutes (not exact second-based windows)',
            ...$counts,
            'rejection_percent_24h'=>$attempts>0?round(100*$counts['rejected_24h']/$attempts,2):0.0,
            'avg_accepted_per_minute_60m'=>round($counts['accepted_60m']/60,2),
            'hourly'=>$buckets,
            'recent_stored_sample'=>[
                'size'=>(int)$quality['sample_size'],'errors'=>(int)$quality['sample_errors'],
                'future_timestamps'=>(int)$quality['future_timestamps'],
                'average_delay_ms'=>$quality['average_delay_ms']===null?null:(float)$quality['average_delay_ms'],
                'p95_delay_ms'=>$quality['p95_delay_ms']===null?null:(float)$quality['p95_delay_ms'],
                'max_sample_size'=>2000,
            ],
            'health'=>self::health($sourceRow,$thresholdMinutes,isset($sourceRow['monitoring_server_time'])?strtotime((string)$sourceRow['monitoring_server_time']):null),
        ];
    }

    /** Bounded maintenance for the fixed-duration operational telemetry. */
    public function prune(int $batch=10000): int
    {
        if ($batch<1||$batch>10000) throw new \InvalidArgumentException('Invalid monitoring cleanup batch.');
        if (!$this->pdo->inTransaction()) throw new \LogicException('Monitoring cleanup requires transaction.');
        $q=$this->pdo->prepare(<<<'SQL'
WITH expired AS (
 SELECT ctid FROM source_ingest_minutes
 WHERE minute<clock_timestamp()-interval '32 days'
 ORDER BY minute LIMIT :batch FOR UPDATE SKIP LOCKED
)
DELETE FROM source_ingest_minutes m USING expired e WHERE m.ctid=e.ctid
SQL);
        $q->bindValue(':batch',$batch,PDO::PARAM_INT);$q->execute();return $q->rowCount();
    }
}
