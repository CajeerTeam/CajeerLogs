<?php
declare(strict_types=1);
namespace Cajeer\Logs\Storage;

use PDO;

/** Explicit, operator-only, bounded hot -> partitioned warm -> verified S3 cold lifecycle. */
final class ArchiveLifecycle
{
    public function __construct(private readonly PDO $pdo,private readonly ?S3CompatibleDisk $s3=null) {}

    public static function uuid(string $raw): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$raw)) throw new \InvalidArgumentException('Invalid project/segment UUID.');
        return strtolower($raw);
    }
    public static function month(string $raw): array
    {
        if (!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$raw)) throw new \InvalidArgumentException('Expected month YYYY-MM.');
        $date=new \DateTimeImmutable($raw.'-01T00:00:00+00:00');
        return [$date->format('Y-m-d H:i:sP'),$date->modify('+1 month')->format('Y-m-d H:i:sP'),$date->format('Ym')];
    }
    public static function limit(string $raw,int $max=500): int
    {
        $limit=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($limit===false) throw new \InvalidArgumentException('Invalid archive batch limit.');
        return $limit;
    }
    private function inTransaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Archive lifecycle must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET LOCAL statement_timeout=15000');
            $this->pdo->exec('SET LOCAL lock_timeout=3000');
            $value=$callback();$this->pdo->commit();return $value;
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack();throw $error; }
    }
    private function eligible(string $project): void
    {
        $s=$this->pdo->prepare('SELECT p.id FROM projects p WHERE p.id=CAST(:project AS uuid) AND p.retention_days IS NULL AND NOT EXISTS (SELECT 1 FROM sources s WHERE s.project_id=p.id AND s.retention_days IS NOT NULL) FOR UPDATE OF p');
        $s->execute(['project'=>$project]);$valid=$s->fetchColumn();$s->closeCursor();
        if ($valid===false) throw new \RuntimeException('Unknown project or project/source retention active; archiving would circumvent retention.');
        // Serialize with retention changes on every source, not only the project row.
        $sources=$this->pdo->prepare('SELECT id FROM sources WHERE project_id=CAST(:project AS uuid) FOR SHARE');
        $sources->execute(['project'=>$project]);$sources->fetchAll();$sources->closeCursor();
    }
    /** Creates a real PostgreSQL monthly partition. DDL serialized by advisory lock. */
    private function ensurePartition(string $yyyymm,string $start,string $end): void
    {
        $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:archive:partitions',0))")->closeCursor();
        $name='archived_log_events_'.$yyyymm;
        // Names/dates are derived exclusively from the validated calendar month.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS $name PARTITION OF archived_log_events FOR VALUES FROM (".$this->pdo->quote($start).") TO (".$this->pdo->quote($end).")");
    }
    public function stage(string $project,string $month,int $limit=100): array
    {
        $project=self::uuid($project);[$start,$end,$yyyymm]=self::month($month);
        if ($limit<1||$limit>500) throw new \InvalidArgumentException('Archive limit must be 1..500.');
        return $this->inTransaction(function () use ($project,$start,$end,$yyyymm,$limit) {
            $this->eligible($project);$this->ensurePartition($yyyymm,$start,$end);
            $s=$this->pdo->prepare('SELECT l.id,l.received_at,to_jsonb(l)::text AS snapshot FROM log_events l WHERE l.project_id=CAST(:project AS uuid)
                AND l.received_at>=CAST(:start AS timestamptz) AND l.received_at<CAST(:end AS timestamptz) AND l.source_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM archived_log_events a WHERE a.id=l.id AND a.received_at=l.received_at)
                ORDER BY l.received_at,l.id LIMIT :limit FOR SHARE OF l SKIP LOCKED');
            $s->bindValue(':project',$project);$s->bindValue(':start',$start);$s->bindValue(':end',$end);$s->bindValue(':limit',$limit,PDO::PARAM_INT);
            $s->execute();$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            $put=$this->pdo->prepare('INSERT INTO archived_log_events(id,project_id,source_id,received_at,snapshot)
                VALUES (CAST(:id AS uuid),CAST(:project AS uuid),CAST(:source AS uuid),CAST(:at AS timestamptz),CAST(:snapshot AS jsonb))
                ON CONFLICT (received_at,id) DO NOTHING');
            $inserted=0;
            foreach ($rows as $row) {
                $record=json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR);
                $put->execute(['id'=>$row['id'],'project'=>$project,'source'=>$record['source_id'],'at'=>$row['received_at'],'snapshot'=>$row['snapshot']]);
                $inserted+=$put->rowCount();
            }
            return ['staged'=>$inserted,'month'=>$yyyymm,'project_id'=>$project,'note'=>'Hot rows remain queryable until explicitly evicted.'];
        });
    }
    public function status(string $project): array
    {
        $project=self::uuid($project);
        $s=$this->pdo->prepare('SELECT count(*) AS rows FROM archived_log_events WHERE project_id=CAST(:project AS uuid)');$s->execute(['project'=>$project]);$warm=(int)$s->fetchColumn();
        $s=$this->pdo->prepare('SELECT count(*) AS objects,coalesce(sum(event_count),0) AS rows,coalesce(sum(bytes),0) AS bytes FROM archived_log_objects WHERE project_id=CAST(:project AS uuid)');
        $s->execute(['project'=>$project]);$cold=$s->fetch(PDO::FETCH_ASSOC);
        return ['project_id'=>$project,'warm_rows'=>$warm,'cold_objects'=>(int)$cold['objects'],'cold_rows'=>(int)$cold['rows'],'cold_bytes'=>(int)$cold['bytes']];
    }
    /** Remove hot rows ONLY if their entire current snapshot matches the durable warm copy. */
    public function evict(string $project,string $month,int $limit=100): array
    {
        $project=self::uuid($project);[$start,$end]=self::month($month);
        if ($limit<1||$limit>500) throw new \InvalidArgumentException('Archive limit must be 1..500.');
        return $this->inTransaction(function () use ($project,$start,$end,$limit) {
            $this->eligible($project);
            $s=$this->pdo->prepare('WITH selected AS (
                SELECT l.id FROM log_events l JOIN archived_log_events a ON a.received_at=l.received_at AND a.id=l.id
                WHERE l.project_id=CAST(:project AS uuid) AND l.received_at>=CAST(:start AS timestamptz) AND l.received_at<CAST(:end AS timestamptz)
                AND a.project_id=l.project_id AND a.source_id=l.source_id AND a.snapshot=to_jsonb(l)
                ORDER BY l.received_at,l.id LIMIT :limit FOR UPDATE OF l SKIP LOCKED
            ), removed AS (DELETE FROM log_events l USING selected s WHERE l.id=s.id RETURNING l.id)
            SELECT count(*) FROM removed');
            $s->bindValue(':project',$project);$s->bindValue(':start',$start);$s->bindValue(':end',$end);$s->bindValue(':limit',$limit,PDO::PARAM_INT);
            $s->execute();
            return ['evicted'=>(int)$s->fetchColumn(),'note'=>'Evicted events are no longer present in hot search or hot analytics. Restore explicitly when needed.'];
        });
    }
    /** Restore warm archive rows to the live table with all original columns; no overwrite. */
    public function restoreHot(string $project,string $month,int $limit=100): array
    {
        $project=self::uuid($project);[$start,$end]=self::month($month);
        if ($limit<1||$limit>500) throw new \InvalidArgumentException('Archive limit must be 1..500.');
        return $this->inTransaction(function () use ($project,$start,$end,$limit) {
            $this->eligible($project);
            $columns='id,occurred_at,received_at,source,service,host,level,message,context,trace_id,request_id,tags,project_id,source_id,ingest_token_id';
            $s=$this->pdo->prepare('WITH candidates AS (
                SELECT a.snapshot FROM archived_log_events a WHERE a.project_id=CAST(:project AS uuid)
                AND a.received_at>=CAST(:start AS timestamptz) AND a.received_at<CAST(:end AS timestamptz)
                -- Limit only genuinely missing hot rows. Previously a repeated restore
                -- could get stuck forever on already-restored entries at the front.
                AND NOT EXISTS (SELECT 1 FROM log_events l WHERE l.id=a.id)
                ORDER BY a.received_at,a.id LIMIT :limit
            ), records AS (SELECT (jsonb_populate_record(NULL::log_events,snapshot)).* FROM candidates)
            INSERT INTO log_events ('.$columns.') SELECT '.$columns.' FROM records WHERE true ON CONFLICT (id) DO NOTHING');
            $s->bindValue(':project',$project);$s->bindValue(':start',$start);$s->bindValue(':end',$end);$s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();
            return ['restored'=>$s->rowCount(),'note'=>'Restored rows are visible in hot queries and their insert triggers run.'];
        });
    }
    private function remote(): S3CompatibleDisk
    {
        if ($this->s3===null) throw new \RuntimeException('S3 not configured for cold operations.');
        return $this->s3;
    }
    /** Export bounded warm rows as a compressed immutable remote segment, verify, then remove warm copies. */
    public function offload(string $project,string $month,int $limit=100): array
    {
        $project=self::uuid($project);[$start,$end,$yyyymm]=self::month($month);
        if ($limit<1||$limit>500) throw new \InvalidArgumentException('Cold batch limit must be 1..500.');
        // Lock archive for a single project/month across concurrent offload processes.
        return $this->inTransaction(function () use ($project,$start,$end,$yyyymm,$limit) {
            $this->eligible($project);
            $this->pdo->prepare("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:cold:' || CAST(:p AS text) || ':' || CAST(:m AS text),0))")
                ->execute(['p'=>$project,'m'=>$yyyymm]);
            $s=$this->pdo->prepare('SELECT id,project_id,source_id,received_at,snapshot::text AS snapshot FROM archived_log_events
                WHERE project_id=CAST(:project AS uuid) AND received_at>=CAST(:start AS timestamptz) AND received_at<CAST(:end AS timestamptz)
                ORDER BY received_at,id LIMIT :limit FOR UPDATE SKIP LOCKED');
            $s->bindValue(':project',$project);$s->bindValue(':start',$start);$s->bindValue(':end',$end);$s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);
            if ($rows===[]) return ['offloaded'=>0];
            $plain='';
            foreach ($rows as $row) {
                $plain.=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
                if (strlen($plain)>16777216) throw new \RuntimeException('Cold segment exceeds 16 MiB before compression; reduce batch.');
            }
            $gzip=gzencode($plain,6,ZLIB_ENCODING_GZIP);
            if ($gzip===false) throw new \RuntimeException('Cold segment compression failed.');
            $id=self::newUuid(); $key='cajeerlogs/archive/v1/'.$project.'/'.$yyyymm.'/'.$id.'.jsonl.gz';
            $object=$this->remote()->putVerified($key,$gzip);
            $s=$this->pdo->prepare('INSERT INTO archived_log_objects(id,project_id,month_start,object_key,sha256,bytes,event_count)
                VALUES(CAST(:id AS uuid),CAST(:project AS uuid),CAST(:month AS date),:key,:sha,:bytes,:count)');
            $s->execute(['id'=>$id,'project'=>$project,'month'=>substr($start,0,10),'key'=>$key,'sha'=>$object['sha256'],
                'bytes'=>$object['bytes'],'count'=>count($rows)]);
            $delete=$this->pdo->prepare('DELETE FROM archived_log_events WHERE id=CAST(:id AS uuid) AND received_at=CAST(:at AS timestamptz)
                AND project_id=CAST(:project AS uuid) AND snapshot=CAST(:snapshot AS jsonb)');
            foreach ($rows as $row) {
                $delete->execute(['id'=>$row['id'],'at'=>$row['received_at'],'project'=>$project,'snapshot'=>$row['snapshot']]);
                if ($delete->rowCount()!==1) throw new \RuntimeException('Warm archive changed while offloading.');
            }
            return ['offloaded'=>count($rows),'object'=>$key,'sha256'=>$object['sha256'],'bytes'=>$object['bytes']];
        });
    }
    /** Rehydrate an entire remote object into its original warm monthly partition. */
    public function restoreCold(string $project,string $objectId): array
    {
        $project=self::uuid($project);$objectId=self::uuid($objectId);
        $s=$this->pdo->prepare('SELECT * FROM archived_log_objects WHERE id=CAST(:id AS uuid) AND project_id=CAST(:project AS uuid)');
        $s->execute(['id'=>$objectId,'project'=>$project]);$meta=$s->fetch(PDO::FETCH_ASSOC);
        if (!$meta) throw new \RuntimeException('Unknown cold segment.');
        $payload=$this->remote()->getVerified($meta['object_key'],$meta['sha256']);
        $rows=ColdSegment::decode($payload,$meta,$project);
        $month=substr((string)$meta['month_start'],0,7);[$start,$end,$yyyymm]=self::month($month);
        return $this->inTransaction(function () use ($project,$rows,$start,$end,$yyyymm,$objectId,$meta) {
            $this->eligible($project);
            // The remote GET happens before the transaction, so a concurrent
            // purge could have removed the catalog record in the meantime.
            // The project lock serializes against purgeCold and policy edits;
            // the row lock additionally protects the exact immutable metadata.
            $this->assertCurrentColdManifest($project,$objectId,$meta);
            $this->ensurePartition($yyyymm,$start,$end);
            $put=$this->pdo->prepare('INSERT INTO archived_log_events(id,project_id,source_id,received_at,snapshot)
                VALUES(CAST(:id AS uuid),CAST(:project AS uuid),CAST(:source AS uuid),CAST(:at AS timestamptz),CAST(:snapshot AS jsonb))
                ON CONFLICT(received_at,id) DO NOTHING');
            $restored=0;
            foreach ($rows as $row) {
                $put->execute(['id'=>$row['id'],'project'=>$project,'source'=>$row['source_id'],'at'=>$row['received_at'],'snapshot'=>$row['snapshot']]);
                $restored+=$put->rowCount();
                if ($put->rowCount()===0) {
                    $check=$this->pdo->prepare('SELECT snapshot=CAST(:snapshot AS jsonb) FROM archived_log_events WHERE id=CAST(:id AS uuid) AND received_at=CAST(:at AS timestamptz)');
                    $check->execute(['snapshot'=>$row['snapshot'],'id'=>$row['id'],'at'=>$row['received_at']]);
                    if (!in_array($check->fetchColumn(),[true,'t','1',1],true)) throw new \RuntimeException('Existing warm record differs from verified cold snapshot.');
                }
            }
            return ['restored'=>$restored,'rows'=>count($rows)];
        });
    }
    /** Revalidate after remote I/O, with the owning project already locked. */
    private function assertCurrentColdManifest(string $project,string $objectId,array $expected): void
    {
        $s=$this->pdo->prepare('SELECT * FROM archived_log_objects WHERE id=CAST(:id AS uuid) AND project_id=CAST(:project AS uuid) FOR UPDATE');
        $s->execute(['id'=>$objectId,'project'=>$project]);
        $current=$s->fetch(PDO::FETCH_ASSOC);
        if (!$current || !ColdSegment::sameManifest($expected,$current)) {
            throw new \RuntimeException('Cold segment catalog changed during download; restoration refused.');
        }
    }
    /** Read and validate the actual remote bytes, not just PostgreSQL metadata. */
    public function verifyCold(string $project,string $objectId): array
    {
        $project=self::uuid($project);$objectId=self::uuid($objectId);
        $s=$this->pdo->prepare('SELECT * FROM archived_log_objects WHERE id=CAST(:id AS uuid) AND project_id=CAST(:project AS uuid)');
        $s->execute(['id'=>$objectId,'project'=>$project]);
        $meta=$s->fetch(PDO::FETCH_ASSOC);
        if (!$meta) throw new \RuntimeException('Unknown cold segment.');
        $payload=$this->remote()->getVerified($meta['object_key'],$meta['sha256']);
        $rows=ColdSegment::decode($payload,$meta,$project);
        // Do not claim an unreferenced object is healthy if an operator purged
        // its catalog entry while a slow S3 download was in flight.
        $this->inTransaction(function () use ($project,$objectId,$meta): void {
            $s=$this->pdo->prepare('SELECT id FROM projects WHERE id=CAST(:id AS uuid) FOR SHARE');
            $s->execute(['id'=>$project]);
            if ($s->fetchColumn()===false) throw new \RuntimeException('Unknown archive project.');
            $this->assertCurrentColdManifest($project,$objectId,$meta);
        });
        return ['verified'=>true,'project_id'=>$project,'object_id'=>$objectId,
            'sha256'=>$meta['sha256'],'bytes'=>(int)$meta['bytes'],
            'event_count'=>count($rows)];
    }
    /** Bounded full-byte scrub for offsite monitoring and recovery preflight. */
    public function scrubCold(string $project,int $limit=25): array
    {
        $project=self::uuid($project);
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Cold scrub limit must be 1..100.');
        $count=$this->pdo->prepare('SELECT count(*) FROM archived_log_objects WHERE project_id=CAST(:project AS uuid)');
        $count->execute(['project'=>$project]);$total=(int)$count->fetchColumn();
        $s=$this->pdo->prepare('SELECT id FROM archived_log_objects WHERE project_id=CAST(:project AS uuid) ORDER BY created_at DESC,id DESC LIMIT :limit');
        $s->bindValue(':project',$project);$s->bindValue(':limit',$limit,PDO::PARAM_INT);$s->execute();
        $ids=$s->fetchAll(PDO::FETCH_COLUMN);$verified=0;$events=0;$bytes=0;
        foreach ($ids as $id) {
            $result=$this->verifyCold($project,(string)$id);
            $verified++;$events+=$result['event_count'];$bytes+=$result['bytes'];
        }
        $count->execute(['project'=>$project]);$after=(int)$count->fetchColumn();
        $complete=$verified===$total && $total===$after;
        if ($complete) {
            // Detect simultaneous purge + creation even when the total count
            // remains unchanged. Keep this extra query bounded (<=100 rows).
            $s->execute();
            $complete=$s->fetchAll(PDO::FETCH_COLUMN)===$ids;
        }
        return ['verified'=>true,'project_id'=>$project,'verified_objects'=>$verified,
            'verified_events'=>$events,'verified_bytes'=>$bytes,'total_objects'=>$after,
            'complete'=>$complete,'limit'=>$limit];
    }
    public function coldObjects(string $project): array
    {
        $s=$this->pdo->prepare('SELECT id,month_start,object_key,sha256,bytes,event_count,created_at FROM archived_log_objects WHERE project_id=CAST(:project AS uuid) ORDER BY created_at DESC LIMIT 1000');
        $s->execute(['project'=>self::uuid($project)]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Explicit warm archive erasure; useful before configuring project/source retention. */
    public function purgeWarm(string $project,string $month,int $limit=500): array
    {
        $project=self::uuid($project);[$start,$end]=self::month($month);
        if ($limit<1||$limit>500) throw new \InvalidArgumentException('Warm purge limit must be 1..500.');
        return $this->inTransaction(function () use ($project,$start,$end,$limit) {
            $lock=$this->pdo->prepare('SELECT id FROM projects WHERE id=CAST(:id AS uuid) FOR UPDATE');
            $lock->execute(['id'=>$project]);if ($lock->fetchColumn()===false) throw new \RuntimeException('Unknown project.');
            $s=$this->pdo->prepare('WITH selected AS (SELECT id,received_at FROM archived_log_events WHERE project_id=CAST(:project AS uuid)
                AND received_at>=CAST(:start AS timestamptz) AND received_at<CAST(:end AS timestamptz)
                ORDER BY received_at,id LIMIT :limit FOR UPDATE SKIP LOCKED), removed AS (
                DELETE FROM archived_log_events a USING selected s WHERE a.id=s.id AND a.received_at=s.received_at RETURNING a.id
                ) SELECT count(*) FROM removed');
            $s->bindValue(':project',$project);$s->bindValue(':start',$start);$s->bindValue(':end',$end);$s->bindValue(':limit',$limit,PDO::PARAM_INT);
            $s->execute();return ['purged'=>(int)$s->fetchColumn()];
        });
    }

    /** Remote DELETE is idempotent; metadata remains when the remote request fails. */
    public function purgeCold(string $project,string $objectId): array
    {
        $project=self::uuid($project);$objectId=self::uuid($objectId);
        return $this->inTransaction(function () use ($project,$objectId) {
            $lock=$this->pdo->prepare('SELECT id FROM projects WHERE id=CAST(:id AS uuid) FOR UPDATE');
            $lock->execute(['id'=>$project]);if ($lock->fetchColumn()===false) throw new \RuntimeException('Unknown project.');
            $s=$this->pdo->prepare('SELECT object_key FROM archived_log_objects WHERE id=CAST(:id AS uuid) AND project_id=CAST(:project AS uuid) FOR UPDATE');
            $s->execute(['id'=>$objectId,'project'=>$project]);$key=$s->fetchColumn();
            if ($key===false) throw new \RuntimeException('Unknown cold segment.');
            $this->remote()->delete((string)$key);
            $s=$this->pdo->prepare('DELETE FROM archived_log_objects WHERE id=CAST(:id AS uuid) AND project_id=CAST(:project AS uuid)');
            $s->execute(['id'=>$objectId,'project'=>$project]);
            return ['deleted'=>$s->rowCount()];
        });
    }

    private static function newUuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
