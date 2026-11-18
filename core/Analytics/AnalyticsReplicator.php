<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

use Cajeer\Logs\Log\LogQuery;
use PDO;

/** PostgreSQL transactional outbox -> versioned ClickHouse mirror. No HTTP in ingest transactions. */
final class AnalyticsReplicator
{
    private readonly ?ClickHouseAnalyticsStore $replica;
    public function __construct(private readonly PDO $db, ?ClickHouseHttpClient $clickhouse=null)
    {
        $this->replica=$clickhouse===null?null:new ClickHouseAnalyticsStore($clickhouse,true);
    }

    /** At most 100 rows are leased; each lease is checked again during acknowledgement. */
    public function claim(int $limit=25): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Replication claim limit must be 1..100.');
        if ($this->db->inTransaction()) throw new \LogicException('Replication worker owns its transaction.');
        $this->db->beginTransaction();
        try {
            $sql=<<<'SQL'
                WITH selected AS (
                    SELECT event_id FROM analytics_replication_outbox
                    WHERE (status='pending' AND available_at<=clock_timestamp())
                       OR (status='running' AND lease_until<=clock_timestamp())
                    ORDER BY available_at,updated_at,event_id
                    LIMIT :limit FOR UPDATE SKIP LOCKED
                ), claimed AS (
                    UPDATE analytics_replication_outbox o
                    SET status='running',attempts=o.attempts+1,
                        lease_id=gen_random_uuid(),lease_until=clock_timestamp()+interval '120 seconds',
                        updated_at=clock_timestamp()
                    FROM selected s WHERE o.event_id=s.event_id
                    RETURNING o.*
                )
                SELECT c.*,l.source,l.service,l.host,l.level,l.message,l.context::text AS context,
                    l.trace_id,l.request_id,array_to_json(l.tags)::text AS tags
                FROM claimed c LEFT JOIN log_events l ON l.id=c.event_id AND l.project_id=c.project_id
                ORDER BY c.updated_at,c.event_id
                SQL;
            $stmt=$this->db->prepare($sql);
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->execute();
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $this->db->commit();
            return $rows;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private static function iso(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Tombstones reuse the original partition timestamp and use a higher revision. */
    public static function encode(array $row): array
    {
        $deleted=$row['operation']==='delete';
        if (!$deleted && $row['source']===null) throw new \RuntimeException('Source event missing during replication.');
        $tags=$deleted?[]:json_decode((string)($row['tags']??'[]'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($tags) || !array_is_list($tags)) throw new \InvalidArgumentException('Invalid stored log tags.');
        return [
            'id'=>$row['event_id'],'project_id'=>$row['project_id'],'source_id'=>$row['source_id'],
            'occurred_at'=>self::iso($row['occurred_at']),'received_at'=>self::iso($row['received_at']),
            'source'=>$deleted?'':(string)$row['source'],
            'service'=>$deleted?'':(string)($row['service']??''),
            'host'=>$deleted?'':(string)($row['host']??''),
            'level'=>$deleted?'info':(string)$row['level'],
            'message'=>$deleted?'':(string)$row['message'],
            'context'=>$deleted?[]:json_decode((string)($row['context']??'{}'),true,32,JSON_THROW_ON_ERROR),
            'trace_id'=>$deleted?'':(string)($row['trace_id']??''),
            'request_id'=>$deleted?'':(string)($row['request_id']??''),
            'tags'=>$tags,
            'revision'=>(int)$row['revision'],'deleted'=>$deleted?1:0,
        ];
    }

    /** One bounded dispatch run. Uncertain ClickHouse ACK causes safe replay of the same revision. */
    public function work(int $limit=25): array
    {
        if ($this->replica===null) throw new \LogicException('ClickHouse transport required to deliver events.');
        $claims=$this->claim($limit);
        $results=['claimed'=>count($claims),'delivered'=>0,'retry'=>0,'dead'=>0,'stale'=>0];
        if ($claims===[]) return $results;
        // Invalid/oversized individual records cannot poison unrelated projects.
        $send=function(array $batch)use(&$send,&$results):void {
            try {
                $this->replica->recordBatch(array_map(self::encode(...),$batch));
            } catch (\InvalidArgumentException|\JsonException $e) {
                if (count($batch)>1) {
                    $pivot=intdiv(count($batch),2);
                    $send(array_slice($batch,0,$pivot));$send(array_slice($batch,$pivot));
                    return;
                }
                $results[$this->finish($batch[0],false,'invalid_event',true)]++;
                return;
            } catch (\Throwable $e) {
                foreach ($batch as $row) $results[$this->finish($row,false,'clickhouse_unavailable',false)]++;
                return;
            }
            foreach ($batch as $row) $results[$this->finish($row,true)]++;
        };
        // Small batches amortize HTTP costs without breaching the 1 MiB insert cap.
        foreach (array_chunk($claims,25) as $batch) $send($batch);
        return $results;
    }

    /** CAS protects against an obsolete worker acknowledging redaction/deletion. */
    public function finish(array $claim,bool $delivered,string $error='',bool $permanent=false): string
    {
        $attempts=(int)$claim['attempts'];
        $dead=!$delivered && ($permanent || $attempts>=20);
        $state=$delivered?'completed':($dead?'dead':'pending');
        $delay=$delivered || $dead?0:min(3600,5*(2**min(9,max(0,$attempts-1))));
        $stmt=$this->db->prepare(<<<'SQL'
            UPDATE analytics_replication_outbox SET
                status=:status,available_at=clock_timestamp()+CAST(:delay AS integer)*interval '1 second',
                completed_at=CASE WHEN CAST(:done AS boolean) THEN clock_timestamp() ELSE NULL END,
                error_code=:error,lease_id=NULL,lease_until=NULL,updated_at=clock_timestamp()
            WHERE event_id=CAST(:id AS uuid) AND status='running'
                AND lease_id=CAST(:lease AS uuid) AND revision=CAST(:revision AS bigint)
            SQL);
        $stmt->execute(['status'=>$state,'delay'=>$delay,'done'=>$delivered?'true':'false',
            'error'=>$delivered?null:$error,'id'=>$claim['event_id'],
            'lease'=>$claim['lease_id'],'revision'=>$claim['revision']]);
        return $stmt->rowCount()===1?($delivered?'delivered':($dead?'dead':'retry')):'stale';
    }

    /** Resumable bounded history traversal for a single operator-authorized project. */
    public function backfill(string $project,int $limit=1000): array
    {
        $project=LogQuery::uuid($project);
        if ($limit<1 || $limit>2000) throw new \InvalidArgumentException('Backfill limit must be 1..2000.');
        if ($this->db->inTransaction()) throw new \LogicException('Backfill owns its transaction.');
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('INSERT INTO analytics_replication_backfill(project_id) VALUES (CAST(:p AS uuid)) ON CONFLICT DO NOTHING');
            $stmt->execute(['p'=>$project]);
            $stmt=$this->db->prepare('SELECT last_event_id FROM analytics_replication_backfill WHERE project_id=CAST(:p AS uuid) FOR UPDATE');
            $stmt->execute(['p'=>$project]);
            $last=$stmt->fetchColumn();
            // All backfill candidates and outbox insertions share one PostgreSQL
            // transaction and one statement; bounded by the indexed UUID cursor.
            $stmt=$this->db->prepare(<<<'SQL'
                WITH candidates AS MATERIALIZED (
                    SELECT id,project_id,source_id,occurred_at,received_at
                    FROM log_events WHERE project_id=CAST(:p AS uuid) AND source_id IS NOT NULL
                        AND (CAST(:cursor_null AS uuid) IS NULL OR id>CAST(:last AS uuid))
                    ORDER BY id LIMIT :limit
                ), inserted AS (
                    INSERT INTO analytics_replication_outbox(event_id,project_id,source_id,occurred_at,received_at,revision,operation)
                    SELECT id,project_id,source_id,occurred_at,received_at,
                        nextval('analytics_replication_revision_seq'),'upsert' FROM candidates WHERE true
                    ON CONFLICT (event_id) DO NOTHING RETURNING event_id
                )
                SELECT (SELECT count(*) FROM candidates) AS scanned,
                    (SELECT id FROM candidates ORDER BY id DESC LIMIT 1) AS cursor,
                    (SELECT count(*) FROM inserted) AS enqueued
                SQL);
            $stmt->bindValue(':p',$project);
            $stmt->bindValue(':cursor_null',$last===false?null:$last);
            $stmt->bindValue(':last',$last===false?null:$last);
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->execute();$result=$stmt->fetch(PDO::FETCH_ASSOC);
            $count=(int)$result['scanned'];$created=(int)$result['enqueued'];
            $cursor=$result['cursor']??($last===false?null:$last);
            $update=$this->db->prepare(<<<'SQL'
                UPDATE analytics_replication_backfill SET last_event_id=CAST(:cursor AS uuid),
                    scanned=scanned+CAST(:scanned AS integer),queued=queued+CAST(:queued AS integer),
                    updated_at=clock_timestamp() WHERE project_id=CAST(:p AS uuid)
                SQL);
            $update->execute(['cursor'=>$cursor,'scanned'=>$count,'queued'=>$created,'p'=>$project]);
            $this->db->commit();
            return ['project_id'=>$project,'scanned'=>$count,'enqueued'=>$created,
                'cursor'=>$cursor,'end_of_scan'=>$count<$limit];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function replay(string $project,int $limit=100,string $selection='dead'): int
    {
        $project=LogQuery::uuid($project);
        if ($limit<1 || $limit>1000 || !in_array($selection,['dead','completed'],true)) throw new \InvalidArgumentException('Invalid replay options.');
        $sql=<<<'SQL'
            WITH chosen AS (
                SELECT event_id FROM analytics_replication_outbox
                WHERE project_id=CAST(:project AS uuid) AND status=:state
                ORDER BY updated_at,event_id LIMIT :limit FOR UPDATE SKIP LOCKED
            )
            UPDATE analytics_replication_outbox o SET status='pending',attempts=0,
                available_at=clock_timestamp(),error_code=NULL,completed_at=NULL,
                lease_id=NULL,lease_until=NULL,updated_at=clock_timestamp()
            FROM chosen c WHERE o.event_id=c.event_id
            SQL;
        $stmt=$this->db->prepare($sql);$stmt->bindValue(':project',$project);$stmt->bindValue(':state',$selection);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();return $stmt->rowCount();
    }

    public function status(): array
    {
        $counts=array_fill_keys(['pending','running','completed','dead'],0);
        foreach ($this->db->query('SELECT status,count(*) AS n FROM analytics_replication_outbox GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']]=(int)$row['n'];
        }
        $lag=$this->db->query("SELECT EXTRACT(EPOCH FROM clock_timestamp()-MIN(created_at))::bigint FROM analytics_replication_outbox WHERE status IN ('pending','running')")->fetchColumn();
        return ['counts'=>$counts,'oldest_pending_seconds'=>$lag===null?null:(int)$lag,
            'dead'=>$counts['dead']];
    }
}
