<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Http\HttpException;
use PDO;

final class SourceRepository
{
    public const FIELDS='id,project_id,name,slug,created_at,description,metadata,source_type,status,archived_at,updated_at,revision,retention_days,max_body_bytes,max_batch_events,events_per_minute,last_seen_at,accepted_events_total,rejected_events_total';
    public const TYPES=['application','server','nginx','aapanel','custom'];
    public const STATES=['active','disabled','archived'];
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $query,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($query); $s->execute($params); return $s; }
    private function requireTransaction(): void { if (!$this->pdo->inTransaction()) throw new \LogicException('Source operation requires a transaction.'); }
    private static function invalid(string $key): never { throw new HttpException(422,'invalid_payload','Invalid source field: '.$key); }
    public static function changes(array $data): array
    {
        $out=[];
        foreach ($data as $key=>$value) {
            if ($key==='source_type') { if (!in_array($value,self::TYPES,true)) self::invalid($key); }
            elseif (in_array($key,['retention_days','max_body_bytes','max_batch_events','events_per_minute'],true) && $value===null) { /* inherit project policy */ }
            elseif (in_array($key,['name','description','metadata','retention_days','max_body_bytes','max_batch_events','events_per_minute'],true)) {
                $value=ProjectRepository::changes([$key=>$value])[$key];
            } else self::invalid($key);
            $out[$key]=$value;
        }
        if (!$out) self::invalid('empty patch');
        return $out;
    }
    public static function decode(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) $row['metadata']=json_decode($row['metadata'],false,32,JSON_THROW_ON_ERROR);
        foreach (['revision','accepted_events_total','rejected_events_total'] as $key) if (isset($row[$key])) $row[$key]=(string)$row[$key];
        foreach (['retention_days','max_body_bytes','max_batch_events','events_per_minute'] as $key) if (isset($row[$key])) $row[$key]=(int)$row[$key];
        return $row;
    }
    public function create(string $projectId,string $name,string $slug,array $options=[]): string
    {
        ProjectRepository::validate($name,$slug);
        // Existing callers may bootstrap an older schema before running later migrations.
        $params=['project'=>$projectId,'name'=>$name,'slug'=>$slug];
        $columns=['project_id','name','slug']; $values=[':project',':name',':slug'];
        if ($options) foreach (self::changes($options) as $key=>$value) {
            if ($key==='name') self::invalid($key);
            $columns[]=$key; $values[]=$key==='metadata'?'CAST(:metadata AS jsonb)':':'.$key; $params[$key]=$value;
        }
        return (string)$this->sql('INSERT INTO sources('.implode(',',$columns).') VALUES ('.implode(',',$values).') RETURNING id',$params)->fetchColumn();
    }
    public function find(string $projectId,string $id): ?array
    {
        $row=$this->sql('SELECT * FROM sources WHERE project_id=:project AND id=:id',['project'=>$projectId,'id'=>$id])->fetch();
        return $row?self::decode($row):null;
    }
    public function update(string $project,string $id,string $revision,array $changes): array
    {
        $this->requireTransaction(); $changes=self::changes($changes);
        if (array_key_exists('retention_days',$changes)) $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:retention:' || current_schema(),0))")->closeCursor();
        $sets=[]; foreach ($changes as $key=>$value) $sets[]=$key.'='.($key==='metadata'?'CAST(:metadata AS jsonb)':':'.$key);
        return $this->mutate($project,$id,$revision,implode(',',$sets),$changes);
    }
    /** Archive is terminal until explicit restore; restoring leaves the source disabled. */
    public function transition(string $project,string $id,string $revision,string $action): array
    {
        $this->requireTransaction();
        [$state,$allowed]=match($action){'disable'=>['disabled',['active','disabled']], 'enable'=>['active',['active','disabled']], 'archive'=>['archived',self::STATES], 'restore'=>['disabled',['archived']], default=>throw new \InvalidArgumentException('Invalid source action.')};
        $row=$this->sql('SELECT status FROM sources WHERE project_id=:project AND id=:id FOR UPDATE',['project'=>$project,'id'=>$id])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Source not found.');
        if (!in_array($row['status'],$allowed,true)) throw new HttpException(409,'source_state_conflict','Source state does not permit this transition.');
        return $this->mutate($project,$id,$revision,'status=:status,archived_at='.($state==='archived'?'COALESCE(archived_at,clock_timestamp())':'NULL'),['status'=>$state]);
    }
    private function mutate(string $project,string $id,string $revision,string $sets,array $params): array
    {
        $row=$this->sql('UPDATE sources SET '.$sets.',revision=revision+1,updated_at=clock_timestamp() WHERE project_id=:project AND id=:id AND revision=CAST(:revision AS bigint) RETURNING '.self::FIELDS,$params+['project'=>$project,'id'=>$id,'revision'=>ProjectRepository::revision($revision)])->fetch();
        if (!$row) {
            if (!$this->find($project,$id)) throw new HttpException(404,'not_found','Source not found.');
            throw new HttpException(409,'source_revision_conflict','Source changed; reload before retrying.');
        }
        return self::decode($row);
    }
    /** Call after project admission. Sorted source locks serialize status/policy changes and quota counters. */
    public function admit(string $project,array $counts,int $bytes): void
    {
        $this->requireTransaction();
        if (!$counts||$bytes<1||$bytes>1048576) throw new \InvalidArgumentException('Invalid source admission.');
        $batch=self::admissionRows($counts);
        // Acquire every source lock in UUID order in one round trip, then check all
        // policies before modifying any counters. This preserves the project->source
        // lock hierarchy and prevents opposite-order mixed batches from deadlocking.
        $rows=$this->sql("SELECT s.id,s.status,s.max_body_bytes,s.max_batch_events,s.events_per_minute,b.events
            FROM sources s JOIN jsonb_to_recordset(CAST(:batch AS jsonb)) AS b(id uuid,events integer) ON s.id=b.id
            WHERE s.project_id=:project ORDER BY s.id FOR UPDATE OF s",['project'=>$project,'batch'=>$batch])->fetchAll();
        if (count($rows)!==count($counts)) throw new HttpException(403,'forbidden','Access denied.');
        foreach ($rows as $row) {
            $events=(int)$row['events'];
            if ($row['status']!=='active') throw new HttpException(409,'source_inactive','Source is disabled or archived.');
            if (($row['max_body_bytes']!==null && $bytes>(int)$row['max_body_bytes'])||($row['max_batch_events']!==null && $events>(int)$row['max_batch_events'])) throw new HttpException(413,'source_payload_limit','Source ingestion size limit exceeded.');
            if ($row['events_per_minute']!==null && $events>(int)$row['events_per_minute']) self::quotaExceeded();
        }
        // Quota buckets remain ordered by locked source UUID. Unlimited sources
        // have no bucket writes. An admission savepoint rolls back the entire set.
        foreach ($rows as $row) {
            if ($row['events_per_minute']===null) continue;
            $hits=$this->sql("INSERT INTO auth_rate_limits(bucket_key,window_start,hits) VALUES (:key,floor(extract(epoch from clock_timestamp())/60)::bigint*60,:events) ON CONFLICT(bucket_key,window_start) DO UPDATE SET hits=auth_rate_limits.hits+EXCLUDED.hits WHERE auth_rate_limits.hits+EXCLUDED.hits<=:quota RETURNING hits",['key'=>hash('sha256','source-events:'.$row['id'].':60'),'events'=>(int)$row['events'],'quota'=>(int)$row['events_per_minute']])->fetchColumn();
            if ($hits===false) self::quotaExceeded();
        }
        $updated=$this->sql("UPDATE sources s SET last_seen_at=clock_timestamp(),accepted_events_total=s.accepted_events_total+b.events
            FROM jsonb_to_recordset(CAST(:batch AS jsonb)) AS b(id uuid,events integer) WHERE s.project_id=:project AND s.id=b.id",['project'=>$project,'batch'=>$batch])->rowCount();
        if ($updated!==count($counts)) throw new \RuntimeException('Incomplete source admission; roll back transaction.');
    }
    private static function admissionRows(array $counts): string
    {
        if (!$counts || count($counts)>100) throw new \InvalidArgumentException('Invalid source admission count.');
        ksort($counts,SORT_STRING); $rows=[]; $total=0;
        foreach ($counts as $id=>$events) {
            if (!is_string($id) || !is_int($events)||$events<1||$events>100 || ($total+=$events)>100) throw new \InvalidArgumentException('Invalid source event count.');
            $rows[]=['id'=>\Cajeer\Logs\Log\LogQuery::uuid($id),'events'=>$events];
        }
        return json_encode($rows,JSON_THROW_ON_ERROR);
    }
    /** Called only after an admission savepoint has rolled back. Counts known, authenticated policy denials. */
    public function recordPolicyRejection(string $project,array $counts): void
    {
        $this->requireTransaction(); $batch=self::admissionRows($counts);
        // UPDATE alone does not guarantee lock order. Lock the exact same source
        // set in order before the bulk write, including the rejection path.
        $locked=$this->sql("SELECT s.id FROM sources s JOIN jsonb_to_recordset(CAST(:batch AS jsonb)) AS b(id uuid,events integer) ON s.id=b.id WHERE s.project_id=:project ORDER BY s.id FOR UPDATE OF s",['project'=>$project,'batch'=>$batch])->fetchAll();
        if (count($locked)!==count($counts)) throw new HttpException(403,'forbidden','Access denied.');
        $updated=$this->sql("UPDATE sources s SET rejected_events_total=s.rejected_events_total+b.events FROM jsonb_to_recordset(CAST(:batch AS jsonb)) AS b(id uuid,events integer) WHERE s.project_id=:project AND s.id=b.id",['project'=>$project,'batch'=>$batch])->rowCount();
        if ($updated!==count($counts)) throw new \RuntimeException('Incomplete rejection accounting; roll back transaction.');
    }
    private static function quotaExceeded(): never { throw new HttpException(429,'source_quota_exceeded','Source event quota exceeded.',['Retry-After'=>'60']); }
    public function statistics(string $project,string $id): array
    {
        $row=$this->sql("SELECT s.last_seen_at,s.accepted_events_total,s.rejected_events_total,(SELECT count(*) FROM log_events l WHERE l.project_id=s.project_id AND l.source_id=s.id) AS events,(SELECT count(*) FROM log_events l WHERE l.project_id=s.project_id AND l.source_id=s.id AND received_at>=now()-interval '24 hours') AS events_24h,(SELECT count(*) FROM log_events l WHERE l.project_id=s.project_id AND l.source_id=s.id AND received_at>=now()-interval '60 seconds') AS events_60s,(SELECT max(received_at) FROM log_events l WHERE l.project_id=s.project_id AND l.source_id=s.id) AS last_received_at,(SELECT count(*) FROM api_tokens t WHERE t.project_id=s.project_id AND t.source_id=s.id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now())) AS active_tokens FROM sources s WHERE s.project_id=:project AND s.id=:id",['project'=>$project,'id'=>$id])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Source not found.');
        foreach (['events','events_24h','events_60s','active_tokens'] as $key) $row[$key]=(int)$row[$key];
        foreach (['accepted_events_total','rejected_events_total'] as $key) $row[$key]=(string)$row[$key];
        return $row;
    }
}
