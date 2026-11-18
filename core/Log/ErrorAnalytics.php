<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Exact error grouping over the scoped PostgreSQL event store.
 * No asynchronous materialized cache: retention, update, redact and replay
 * are reflected in the next repeatable-read analytics query. */
final class ErrorAnalytics
{
    public const LEVELS=['error','critical','alert','emergency'];
    public const MAX_GROUPS=100;
    public function __construct(private readonly PDO $pdo) {}

    public static function signatureSql(): string
    {
        return "log_error_signature(message,project_id,coalesce(service,''))";
    }
    /** One snapshot-friendly SQL statement for exact totals, top groups and UTC trend. */
    public static function statement(LogQuery $query): array
    {
        if (!LogQuery::boundedRange($query->filters)) throw new \InvalidArgumentException('Error analytics requires a bounded 31-day window.');
        [$where,$params]=LogRepository::predicate($query);
        $bucket=match($query->interval) {
            'minute','hour','day'=>"date_trunc('{$query->interval}',occurred_at AT TIME ZONE 'UTC')",
            '5min'=>"date_bin(interval '5 minutes',occurred_at AT TIME ZONE 'UTC',timestamp '2000-01-01')",
        };
        $signature=self::signatureSql();
        $sql=<<<SQL
            WITH filtered AS MATERIALIZED (
                SELECT id,source_id,level,occurred_at,$signature AS fingerprint,$bucket AS bucket
                FROM log_events
                WHERE $where AND level IN ('error','critical','alert','emergency')
            ), grouped AS (
                SELECT fingerprint,bucket,count(*) AS n,min(occurred_at) AS first_seen,
                    max(occurred_at) AS last_seen,count(DISTINCT source_id) AS sources,
                    max(id::text) AS sample_id,
                    CASE WHEN GROUPING(fingerprint)=0 THEN 'group'
                         WHEN GROUPING(bucket)=0 THEN 'bucket' ELSE 'total' END AS kind
                FROM filtered GROUP BY GROUPING SETS ((),(fingerprint),(bucket))
            ), ranked AS (
                SELECT *,count(*) OVER(PARTITION BY kind) AS group_count,
                    row_number() OVER(PARTITION BY kind ORDER BY n DESC,fingerprint,bucket) AS rn
                FROM grouped
            )
            SELECT kind,fingerprint,to_char(bucket,'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS bucket,
                n,to_char(first_seen AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS first_seen,
                to_char(last_seen AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS last_seen,
                sources,sample_id,group_count,left(sample.message,180) AS example_message
            FROM ranked LEFT JOIN log_events sample ON ranked.kind='group' AND sample.id=CAST(ranked.sample_id AS uuid)
            WHERE kind<>'group' OR rn<=100
            ORDER BY kind,bucket,n DESC,fingerprint
            SQL;
        return [$sql,$params];
    }
    public function period(LogQuery $query): array
    {
        [$sql,$params]=self::statement($query);
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);
        $result=['total'=>0,'group_count'=>0,'groups_truncated'=>false,'groups'=>[],'buckets'=>[]];
        foreach ($stmt as $row) {
            $n=(int)$row['n'];
            if ($row['kind']==='total') $result['total']=$n;
            elseif ($row['kind']==='bucket') {
                $result['buckets'][]=['timestamp'=>$row['bucket'],'count'=>$n];
            } elseif ($row['kind']==='group') {
                $result['group_count']=(int)$row['group_count'];
                $result['groups'][]=['fingerprint'=>trim($row['fingerprint']),'count'=>$n,
                    'first_seen'=>$row['first_seen'],'last_seen'=>$row['last_seen'],
                    'source_count'=>(int)$row['sources'],'sample_event_id'=>$row['sample_id'],
                    'example_message'=>$row['example_message']];
            }
        }
        $result['groups_truncated']=$result['group_count']>self::MAX_GROUPS;
        return $result;
    }
    /** Equal-duration preceding range, with exactly the same verified project/source filters. */
    public static function previous(LogQuery $query): LogQuery
    {
        $from=new DateTimeImmutable($query->filters['from']);
        $to=new DateTimeImmutable($query->filters['to']);
        $seconds=(float)$to->format('U.u')-(float)$from->format('U.u');
        $microseconds=(int)round($seconds*1000000);
        $previousFrom=$from->modify('-'.$microseconds.' microseconds');
        $filters=$query->filters;
        $filters['from']=$previousFrom->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $filters['to']=$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        return LogQuery::parse($filters+['interval'=>$query->interval],'errors');
    }
    public static function compare(array $current,array $previous): array
    {
        $indexed=[];foreach ($previous['groups'] as $row) $indexed[$row['fingerprint']]=$row['count'];
        foreach ($current['groups'] as &$row) {
            $known=array_key_exists($row['fingerprint'],$indexed);
            $prior=$known?$indexed[$row['fingerprint']]:($previous['groups_truncated']?null:0);
            $row['previous_count']=$prior;
            $row['change']=$prior===null?null:$row['count']-$prior;
            $row['classification']=$prior===null?'unknown':($prior===0?'new':($row['count']>$prior?'regressed':'recurring'));
        } unset($row);
        return $current;
    }
    public function report(LogQuery $query): array
    {
        $current=$this->period($query);
        $before=$this->previous($query);
        $previous=$this->period($before);
        $current=self::compare($current,$previous);
        return ['project_id'=>$query->filters['project_id'],
            'source_id'=>$query->filters['source_id']??null,
            'from'=>$query->filters['from'],'to'=>$query->filters['to'],
            'previous_from'=>$before->filters['from'],'previous_to'=>$before->filters['to'],
            'interval'=>$query->interval,'total'=>$current['total'],'previous_total'=>$previous['total'],
            'change'=>$current['total']-$previous['total'],
            'group_count'=>$current['group_count'],'groups_truncated'=>$current['groups_truncated'],
            'previous_groups_truncated'=>$previous['groups_truncated'],
            'groups'=>$current['groups'],'buckets'=>$current['buckets']];
    }
    public static function csv(array $report): string
    {
        $stream=fopen('php://temp/maxmemory:1048576','w+b');
        if ($stream===false) throw new \RuntimeException('Cannot create report stream.');
        try {
            $keys=['kind','from','to','total','previous_total','group_count','groups_truncated',
                'fingerprint','count','previous_count','change','classification','source_count',
                'first_seen','last_seen','sample_event_id','example_message','bucket_timestamp'];
            self::csvWrite($stream,$keys);
            $summary=array_fill_keys($keys,'');
            $summary['kind']='summary';$summary['from']=$report['from'];$summary['to']=$report['to'];
            $summary['total']=$report['total'];$summary['previous_total']=$report['previous_total'];
            $summary['group_count']=$report['group_count'];
            $summary['groups_truncated']=$report['groups_truncated']?'true':'false';
            self::csvWrite($stream,array_values($summary));
            foreach ($report['groups'] as $group) {
                $row=array_fill_keys($keys,'');
                $row['kind']='group';
                foreach (['fingerprint','count','previous_count','change','classification','source_count','first_seen','last_seen','sample_event_id','example_message'] as $key) {
                    $row[$key]=$group[$key]??'';
                }
                self::csvWrite($stream,array_values($row));
            }
            foreach ($report['buckets'] as $bucket) {
                $row=array_fill_keys($keys,'');
                $row['kind']='bucket';$row['count']=$bucket['count'];$row['bucket_timestamp']=$bucket['timestamp'];
                self::csvWrite($stream,array_values($row));
            }
            rewind($stream);$data=stream_get_contents($stream);
            if ($data===false || strlen($data)>5*1024*1024) throw new \RuntimeException('Error report exceeds the download budget.');
            return $data;
        } finally { fclose($stream); }
    }
    private static function csvWrite($stream,array $values): void
    {
        $cells=[];
        foreach ($values as $cell) {
            $value=(string)$cell;
            if (preg_match('/^(?:[\s\x00-\x1f\x7f]*[=+\-@＝＋－＠]|[\t\r\n])/u',$value)) $value="'".$value;
            $cells[]=$value;
        }
        if (fputcsv($stream,$cells,',','"','',"\r\n")===false) throw new \RuntimeException('Cannot write CSV report.');
    }
}
