<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

use Cajeer\Logs\Log\LogQuery;
use DateTimeImmutable;
use PDO;

/** Exact minute rollups; transactional mutation triggers and resumable snapshot backfill. */
final class PostgresRollupStore
{
    public function __construct(private readonly PDO $db) {}

    /** Backfill is idempotent even while updates and deletes are occurring.
     * The selected log rows remain SHARE-locked until counted, forcing writers
     * to wait for the same committed state and then retract it if necessary. */
    public function backfill(string $project,int $limit=1000): array
    {
        $project=LogQuery::uuid($project);
        if ($limit<1 || $limit>2000) throw new \InvalidArgumentException('Rollup backfill limit must be 1..2000.');
        if ($this->db->inTransaction()) throw new \LogicException('Backfill owns its transaction.');
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('INSERT INTO analytics_rollup_backfill(project_id) VALUES (CAST(:p AS uuid)) ON CONFLICT DO NOTHING');
            $stmt->execute(['p'=>$project]);
            $stmt=$this->db->prepare('SELECT last_event_id,complete FROM analytics_rollup_backfill WHERE project_id=CAST(:p AS uuid) FOR UPDATE');
            $stmt->execute(['p'=>$project]);
            $record=$stmt->fetch(PDO::FETCH_ASSOC);
            if (in_array($record['complete'],[true,'t','true','1',1],true)) {
                $this->db->commit();
                return ['project_id'=>$project,'scanned'=>0,'applied'=>0,'cursor'=>$record['last_event_id'],'complete'=>true];
            }
            $cursor=$record['last_event_id'];
            $stmt=$this->db->prepare(<<<'SQL'
                WITH candidates AS MATERIALIZED (
                    SELECT id,project_id,date_trunc('minute',occurred_at) AS minute,
                        source_id,source,level,coalesce(service,'') AS service,coalesce(host,'') AS host
                    FROM log_events
                    WHERE project_id=CAST(:project AS uuid) AND source_id IS NOT NULL
                      AND (CAST(:cursor_missing AS uuid) IS NULL OR id>CAST(:cursor AS uuid))
                    ORDER BY id LIMIT :limit FOR SHARE
                ), inserted AS (
                    INSERT INTO analytics_rollup_event_state(event_id,project_id,minute,source_id,source,level,service,host)
                    SELECT id,project_id,minute,source_id,source,level,service,host FROM candidates
                    ON CONFLICT (event_id) DO NOTHING
                    RETURNING project_id,minute,source_id,source,level,service,host
                ), grouped AS (
                    SELECT project_id,minute,source_id,source,level,service,host,count(*) AS n
                    FROM inserted GROUP BY project_id,minute,source_id,source,level,service,host
                ), counted AS (
                    INSERT INTO analytics_rollup_minutes(project_id,minute,source_id,source,level,service,host,events)
                    SELECT project_id,minute,source_id,source,level,service,host,n FROM grouped
                    ON CONFLICT (project_id,minute,source_id,source,level,service,host)
                    DO UPDATE SET events=analytics_rollup_minutes.events+EXCLUDED.events
                    RETURNING 1
                )
                SELECT (SELECT count(*) FROM candidates) AS scanned,
                    (SELECT count(*) FROM inserted) AS applied,
                    (SELECT id FROM candidates ORDER BY id DESC LIMIT 1) AS cursor,
                    (SELECT count(*) FROM counted) AS written_groups
                SQL);
            $stmt->bindValue(':project',$project);
            $stmt->bindValue(':cursor_missing',$cursor);
            $stmt->bindValue(':cursor',$cursor);
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->execute();$row=$stmt->fetch(PDO::FETCH_ASSOC);
            $scanned=(int)$row['scanned'];$applied=(int)$row['applied'];
            $cursor=$row['cursor']??$cursor;
            $complete=$scanned<$limit;
            $stmt=$this->db->prepare(<<<'SQL'
                UPDATE analytics_rollup_backfill SET last_event_id=CAST(:cursor AS uuid),
                    scanned=scanned+CAST(:scanned AS bigint),applied=applied+CAST(:applied AS bigint),
                    complete=CAST(:complete AS boolean),updated_at=clock_timestamp()
                WHERE project_id=CAST(:project AS uuid)
                SQL);
            $stmt->execute(['cursor'=>$cursor,'scanned'=>$scanned,'applied'=>$applied,
                'complete'=>$complete?'true':'false','project'=>$project]);
            $this->db->commit();
            return ['project_id'=>$project,'scanned'=>$scanned,'applied'=>$applied,'cursor'=>$cursor,'complete'=>$complete];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function status(string $project): array
    {
        $project=LogQuery::uuid($project);
        $stmt=$this->db->prepare(<<<'SQL'
            WITH scope AS (SELECT CAST(:project AS uuid) AS id)
            SELECT b.last_event_id,b.scanned,b.applied,b.complete,b.updated_at,
                (SELECT coalesce(sum(events),0) FROM analytics_rollup_minutes WHERE project_id=scope.id) AS rollup_events,
                (SELECT count(*) FROM analytics_rollup_event_state WHERE project_id=scope.id) AS tracked_events
            FROM analytics_rollup_backfill b CROSS JOIN scope WHERE b.project_id=scope.id
            SQL);
        $stmt->execute(['project'=>$project]);$data=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) return ['project_id'=>$project,'complete'=>false,'scanned'=>0,'applied'=>0];
        $data['complete']=in_array($data['complete'],[true,'t','true','1',1],true);
        foreach (['scanned','applied','rollup_events','tracked_events'] as $k) $data[$k]=(int)$data[$k];
        return ['project_id'=>$project]+$data;
    }

    /** Never read partial rollups; restrictive filters are delegated to raw SQL. */
    public function eligible(LogQuery $query): bool
    {
        if (array_diff(array_keys($query->filters),['project_id','source_id','source','level','service','host','from','to'])!==[]) return false;
        // Legacy events can have a project without a source_id; they cannot
        // enter a source-granular counter. A partial index makes this guard
        // cheap even on a very large event table.
        $stmt=$this->db->prepare(<<<'SQL'
            WITH scope AS (SELECT CAST(:p AS uuid) AS id)
            SELECT coalesce((SELECT complete FROM analytics_rollup_backfill WHERE project_id=scope.id),false)
                AND NOT EXISTS (SELECT 1 FROM log_events WHERE project_id=scope.id AND source_id IS NULL)
            FROM scope
            SQL);
        $stmt->execute(['p'=>$query->filters['project_id']]);
        return in_array($stmt->fetchColumn(),[true,'t','true','1',1],true);
    }

    /** Full minute bins are read from counters; partial boundary minutes are
     * read from log_events so arbitrary RFC3339 endpoints remain exact. */
    public function statistics(LogQuery $query): array
    {
        $f=$query->filters;
        $from=new DateTimeImmutable($f['from']);$to=new DateTimeImmutable($f['to']);
        $toUtc=static fn(int $s):string=>gmdate('Y-m-d H:i:s',$s).'+00';
        $fs=$from->getTimestamp();$ts=$to->getTimestamp();
        $fullStart=$fs+(($from->format('s.u')==='00.000000')?0:60-(int)$from->format('s'));
        $fullEnd=$ts-(int)$to->format('s');
        $params=['project'=>$f['project_id'],'from'=>$f['from'],'to'=>$f['to'],
            'full_start'=>$toUtc($fullStart),'full_end'=>$toUtc($fullEnd)];
        $pred=[];$scopeColumns=[];
        foreach (['source_id','source','level','service','host'] as $key) {
            if (!isset($f[$key])) continue;
            $scopeColumns[]=',CAST(:'.$key.' AS '.($key==='source_id'?'uuid':'text').') AS '.$key;
            $pred[]='AND %s.'.$key.'=scope.'.$key;
            $params[$key]=$f[$key];
        }
        $scopeFields=implode(' ',$scopeColumns);
        $for=static fn(string $alias):string=>implode(' ',array_map(static fn(string $p):string=>sprintf($p,$alias),$pred));
        // Compute requested coarser bins in PostgreSQL rather than fetching
        // and merging up to 44,640 minute rows in the PHP process.
        $bucket=match($query->interval) {
            'minute'=>"minute AT TIME ZONE 'UTC'",
            '5min'=>"date_bin(interval '5 minutes',minute AT TIME ZONE 'UTC',timestamp '2000-01-01')",
            'hour'=>"date_trunc('hour',minute AT TIME ZONE 'UTC')",
            'day'=>"date_trunc('day',minute AT TIME ZONE 'UTC')",
        };
        $sql=<<<SQL
            WITH scope AS (
                SELECT CAST(:project AS uuid) AS project_id,
                    CAST(:from AS timestamptz) AS start_time,
                    CAST(:to AS timestamptz) AS end_time,
                    CAST(:full_start AS timestamptz) AS full_start,
                    CAST(:full_end AS timestamptz) AS full_end $scopeFields
            ), partial AS (
                SELECT date_trunc('minute',e.occurred_at) AS minute,e.source_id,e.source,e.level,
                    coalesce(e.service,'') AS service,coalesce(e.host,'') AS host,count(*) AS n
                FROM log_events e CROSS JOIN scope
                WHERE e.project_id=scope.project_id AND
                    ((e.occurred_at>=scope.start_time AND e.occurred_at<LEAST(scope.full_start,scope.end_time))
                     OR (e.occurred_at>=GREATEST(scope.full_start,scope.full_end)
                        AND e.occurred_at<scope.end_time))
                    {$for('e')}
                GROUP BY date_trunc('minute',e.occurred_at),e.source_id,e.source,e.level,coalesce(e.service,''),coalesce(e.host,'')
            ), all_rows AS (
                SELECT m.minute,m.source_id,m.source,m.level,m.service,m.host,m.events AS n
                FROM analytics_rollup_minutes m CROSS JOIN scope
                WHERE m.project_id=scope.project_id AND m.events>0
                    AND m.minute>=scope.full_start AND m.minute<scope.full_end
                    {$for('m')}
                UNION ALL
                SELECT minute,source_id,source,level,service,host,n FROM partial
            ), normalized AS (
                SELECT source_id,source,level,service,host,$bucket AS bucket,n FROM all_rows
            ), grouped AS (
                SELECT level,source_id,source,service,host,bucket,sum(n) AS n,
                    sum(n) FILTER (WHERE level IN ('error','critical','alert','emergency')) AS errors,
                    CASE WHEN GROUPING(level)=0 THEN 'level' WHEN GROUPING(source_id)=0 THEN 'source'
                         WHEN GROUPING(service)=0 THEN 'service' WHEN GROUPING(host)=0 THEN 'host'
                         WHEN GROUPING(bucket)=0 THEN 'bucket' ELSE 'total' END AS kind
                FROM normalized GROUP BY GROUPING SETS ((),(level),(source_id,source),(bucket),(service),(host))
            ), ranked AS (
                SELECT *,row_number() OVER(PARTITION BY kind ORDER BY n DESC,source_id,source,service,host,level,bucket) AS rn,
                    count(*) OVER(PARTITION BY kind) AS group_count FROM grouped
                WHERE n>0 AND NOT (kind='service' AND service='') AND NOT (kind='host' AND host='')
            )
            SELECT kind,level,source_id,source,service,host,to_char(bucket,'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS bucket,n,
                coalesce(errors,0) AS errors,group_count
            FROM ranked WHERE (kind NOT IN ('source','service','host')) OR (kind='source' AND rn<=100)
                OR (kind IN ('service','host') AND rn<=10)
            ORDER BY kind,bucket,level,n DESC,source_id,source,service,host
            SQL;
        $stmt=$this->db->prepare($sql);$stmt->execute($params);
        $result=['total'=>0,'by_level'=>array_fill_keys(LogQuery::LEVELS,0),'by_source'=>[],
            'source_count'=>0,'sources_truncated'=>false,'by_service'=>[],'by_host'=>[],'buckets'=>[],
            'interval'=>$query->interval,'from'=>$f['from'],'to'=>$f['to']];
        while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $n=(int)$row['n'];
            switch ($row['kind']) {
                case 'total':$result['total']=$n;break;
                case 'level':$result['by_level'][$row['level']]=$n;break;
                case 'source':$result['by_source'][]=['source_id'=>$row['source_id'],'source'=>$row['source'],'count'=>$n];
                    $result['source_count']=(int)$row['group_count'];$result['sources_truncated']=$result['source_count']>100;break;
                case 'service':$result['by_service'][]=['service'=>$row['service'],'count'=>$n];break;
                case 'host':$result['by_host'][]=['host'=>$row['host'],'count'=>$n];break;
                case 'bucket':$result['buckets'][]=['timestamp'=>$row['bucket'],'count'=>$n,'errors'=>(int)$row['errors']];break;
            }
        }
        return $result;
    }

    /** Detect dimension drift, not just matching total event counts. The verifier
     * scans the requested minute-aligned window and reports actual mismatch keys. */
    public function verify(string $project,string $from,string $to): array
    {
        $q=LogQuery::parse(['project_id'=>LogQuery::uuid($project),'from'=>$from,'to'=>$to],'stats');
        foreach (['from','to'] as $key) {
            if (substr($q->filters[$key],17)!=='00.000000Z') {
                throw new \InvalidArgumentException('Rollup verification requires minute-aligned UTC bounds.');
            }
        }
        if ($this->db->inTransaction()) throw new \LogicException('Rollup verification owns its snapshot transaction.');
        $this->db->beginTransaction();
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $stmt=$this->db->prepare(<<<'SQL'
            WITH scope AS (SELECT CAST(:project AS uuid) AS project_id,
                    CAST(:from AS timestamptz) AS start_time,CAST(:to AS timestamptz) AS end_time),
            actual AS (
                SELECT date_trunc('minute',occurred_at) AS minute,source_id,source,level,
                    coalesce(service,'') AS service,coalesce(host,'') AS host,count(*) AS n
                FROM log_events CROSS JOIN scope WHERE log_events.project_id=scope.project_id
                    AND occurred_at>=scope.start_time AND occurred_at<scope.end_time
                GROUP BY date_trunc('minute',occurred_at),source_id,source,level,coalesce(service,''),coalesce(host,'')
            ), counted AS (
                SELECT minute,source_id,source,level,service,host,events AS n
                FROM analytics_rollup_minutes CROSS JOIN scope WHERE analytics_rollup_minutes.project_id=scope.project_id
                    AND minute>=scope.start_time AND minute<scope.end_time
            ), differences AS (
                SELECT coalesce(a.minute,c.minute) AS minute,
                    coalesce(a.source_id,c.source_id) AS source_id,
                    coalesce(a.source,c.source) AS source,
                    coalesce(a.level,c.level) AS level,
                    coalesce(a.service,c.service) AS service,
                    coalesce(a.host,c.host) AS host,
                    coalesce(a.n,0) AS actual,coalesce(c.n,0) AS rollup
                FROM actual a FULL JOIN counted c USING (minute,source_id,source,level,service,host)
                WHERE coalesce(a.n,0)<>coalesce(c.n,0)
            )
            SELECT count(*) AS mismatched_groups,
                coalesce(sum(actual),0) AS mismatched_raw_events,
                coalesce(sum(rollup),0) AS mismatched_rollup_events
            FROM differences
            SQL);
        $params=['project'=>$project,'from'=>$q->filters['from'],'to'=>$q->filters['to']];
        $stmt->execute($params);$errors=$stmt->fetch(PDO::FETCH_ASSOC);
        $stmt=$this->db->prepare(<<<'SQL'
            WITH scope AS (SELECT CAST(:project AS uuid) AS project_id,
                    CAST(:from AS timestamptz) AS start_time,CAST(:to AS timestamptz) AS end_time)
            SELECT
                (SELECT count(*) FROM log_events WHERE project_id=scope.project_id
                    AND occurred_at>=scope.start_time AND occurred_at<scope.end_time) AS raw,
                (SELECT coalesce(sum(events),0) FROM analytics_rollup_minutes WHERE project_id=scope.project_id
                    AND minute>=scope.start_time AND minute<scope.end_time) AS rollup
            FROM scope
            SQL);
        $stmt->execute($params);$totals=$stmt->fetch(PDO::FETCH_ASSOC);
        $result=['project_id'=>$project,'from'=>$q->filters['from'],'to'=>$q->filters['to'],
            'raw'=>(int)$totals['raw'],'rollup'=>(int)$totals['rollup'],
            'mismatched_groups'=>(int)$errors['mismatched_groups'],
            'difference'=>(int)$totals['raw']-(int)$totals['rollup'],
            'match'=>(int)$errors['mismatched_groups']===0 && (int)$totals['raw']===(int)$totals['rollup']];
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

}
