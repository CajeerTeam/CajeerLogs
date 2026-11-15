<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use PDO;

final class ProjectRepository
{
    public const FIELDS = 'id,name,slug,created_at,description,metadata,archived_at,updated_at,revision,retention_days,max_body_bytes,max_batch_events,events_per_minute';
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $query,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($query); $s->execute($params); return $s; }
    public static function validate(string $name, string $slug): void
    {
        if (trim($name) === '' || strlen($name) > 255 || str_contains($name,"\0") || strlen($slug) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) { throw new DatabaseException('Invalid project/source name or slug.'); }
    }
    public function create(string $name, string $slug): string
    {
        self::validate($name, $slug);
        return (string)$this->sql('INSERT INTO projects(name, slug) VALUES (:name, :slug) RETURNING id',['name'=>$name,'slug'=>$slug])->fetchColumn();
    }
    public static function decode(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) { $row['metadata']=json_decode($row['metadata'],false,32,JSON_THROW_ON_ERROR); }
        if (isset($row['revision'])) { $row['revision']=(string)$row['revision']; }
        foreach (['retention_days','max_body_bytes','max_batch_events','events_per_minute'] as $key) { if (isset($row[$key])) $row[$key]=(int)$row[$key]; }
        if (array_key_exists('archived_at',$row)) $row['status']=$row['archived_at']===null?'active':'archived';
        return $row;
    }
    public function find(string $id): ?array
    {
        $row=$this->sql('SELECT '.self::FIELDS.' FROM projects WHERE id=:id',['id'=>$id])->fetch();
        return $row?self::decode($row):null;
    }
    public static function revision(mixed $value): string
    {
        if (!is_string($value)||!preg_match('/^[1-9][0-9]{0,18}$/D',$value)||strlen($value)===19&&strcmp($value,'9223372036854775807')>0) throw new HttpException(422,'invalid_payload','A valid revision string is required.');
        return $value;
    }
    /** Validate PATCH fields before SQL; explicit null clears optional policies. */
    public static function changes(array $data): array
    {
        $out=[];
        foreach ($data as $key=>$value) {
            switch ($key) {
                case 'name':
                    if (!is_string($value)||trim($value)===''||strlen($value)>255||str_contains($value,"\0")) self::invalid($key);
                    break;
                case 'description':
                    if (!is_string($value)||strlen($value)>4096||str_contains($value,"\0")) self::invalid($key);
                    break;
                case 'metadata':
                    if (!is_object($value)) self::invalid($key);
                    try { $value=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE,32); } catch (\JsonException) { self::invalid($key); }
                    if (strlen($value)>8192||str_contains($value,'\\u0000')) self::invalid($key);
                    break;
                case 'retention_days': case 'events_per_minute': case 'max_body_bytes': case 'max_batch_events':
                    [$min,$max]=match($key){'retention_days'=>[1,3650],'events_per_minute'=>[1,1000000],'max_body_bytes'=>[1024,1048576],default=>[1,100]};
                    if ($value===null && in_array($key,['retention_days','events_per_minute'],true)) break;
                    if (!is_int($value)||$value<$min||$value>$max) self::invalid($key);
                    break;
                default: self::invalid($key);
            }
            $out[$key]=$value;
        }
        if (!$out) self::invalid('empty patch');
        return $out;
    }
    private static function invalid(string $key): never { throw new HttpException(422,'invalid_payload','Invalid project field: '.$key); }
    private function requireTransaction(): void { if (!$this->pdo->inTransaction()) throw new \LogicException('Project operation requires a transaction.'); }
    /** Same order as Retention::set: retention lock before project row lock. */
    public function update(string $id,string $revision,array $changes): array
    {
        $this->requireTransaction(); $revision=self::revision($revision); $changes=self::changes($changes);
        if (array_key_exists('retention_days',$changes)) $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:retention:' || current_schema(),0))")->closeCursor();
        $sets=[]; foreach ($changes as $key=>$value) $sets[]=$key.'='.($key==='metadata'?'CAST(:metadata AS jsonb)':':'.$key);
        return $this->mutate($id,$revision,implode(',',$sets),$changes);
    }
    public function archive(string $id,string $revision,bool $archived): array
    {
        $this->requireTransaction();
        return $this->mutate($id,self::revision($revision),'archived_at='.($archived?'COALESCE(archived_at,clock_timestamp())':'NULL'),[]);
    }
    private function mutate(string $id,string $revision,string $sets,array $params): array
    {
        $row=$this->sql('UPDATE projects SET '.$sets.',revision=revision+1,updated_at=clock_timestamp() WHERE id=:id AND revision=CAST(:revision AS bigint) RETURNING '.self::FIELDS,$params+['id'=>$id,'revision'=>$revision])->fetch();
        if (!$row) {
            if (!$this->find($id)) throw new HttpException(404,'not_found','Project not found.');
            throw new HttpException(409,'project_revision_conflict','Project changed; reload before retrying.');
        }
        return self::decode($row);
    }
    /** Admission is serialized with policy changes by a row lock until commit. Already accepted jobs may drain. */
    public function admit(string $id,int $bytes,int $events): void
    {
        $this->requireTransaction();
        if ($bytes<1||$events<1||$events>100) throw new \InvalidArgumentException('Invalid project admission size.');
        $row=$this->sql('SELECT archived_at,max_body_bytes,max_batch_events,events_per_minute FROM projects WHERE id=:id FOR SHARE',['id'=>$id])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Project not found.');
        if ($row['archived_at']!==null) throw new HttpException(409,'project_archived','Project is archived; new ingestion is disabled.');
        if ($bytes>(int)$row['max_body_bytes']||$events>(int)$row['max_batch_events']) throw new HttpException(413,'project_payload_limit','Project ingestion size limit exceeded.');
        if ($row['events_per_minute']===null) return;
        $limit=(int)$row['events_per_minute'];
        if ($events>$limit) throw new HttpException(429,'project_quota_exceeded','Project event quota exceeded.',['Retry-After'=>'60']);
        // Reuse existing cleanup-managed fixed-window buckets. Only committed admissions consume quota.
        $result=$this->sql("INSERT INTO auth_rate_limits(bucket_key,window_start,hits) VALUES (:key,floor(extract(epoch from clock_timestamp())/60)::bigint*60,:events) ON CONFLICT(bucket_key,window_start) DO UPDATE SET hits=auth_rate_limits.hits+EXCLUDED.hits WHERE auth_rate_limits.hits+EXCLUDED.hits<=:quota RETURNING hits",['key'=>hash('sha256','project-events:'.$id.':60'),'events'=>$events,'quota'=>$limit])->fetchColumn();
        if ($result===false) throw new HttpException(429,'project_quota_exceeded','Project event quota exceeded.',['Retry-After'=>'60']);
    }
    public function statistics(string $id): array
    {
        $row=$this->sql("WITH target AS (SELECT id FROM projects WHERE id=:id) SELECT ss.sources,ss.active_sources,ss.disabled_sources,ss.archived_sources,(SELECT count(*) FROM project_members m WHERE m.project_id=p.id) AS members,(SELECT count(*) FROM api_tokens t WHERE t.project_id=p.id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now())) AS active_tokens,(SELECT count(*) FROM log_events l WHERE l.project_id=p.id) AS events,(SELECT count(*) FROM log_events l WHERE l.project_id=p.id AND received_at>=now()-interval '24 hours') AS events_24h,(SELECT max(received_at) FROM log_events l WHERE l.project_id=p.id) AS last_received_at,(SELECT count(*) FROM jobs j WHERE j.project_id=p.id AND queue='ingest-v1' AND status IN ('pending','running')) AS queued_jobs,(SELECT count(*) FROM jobs j WHERE j.project_id=p.id AND queue='ingest-v1' AND status='dead') AS dead_jobs FROM target p CROSS JOIN LATERAL (SELECT count(*) AS sources,count(*) FILTER (WHERE status='active') AS active_sources,count(*) FILTER (WHERE status='disabled') AS disabled_sources,count(*) FILTER (WHERE status='archived') AS archived_sources FROM sources s WHERE s.project_id=p.id) ss",['id'=>$id])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Project not found.');
        foreach ($row as $key=>&$value) if ($key!=='last_received_at') $value=(int)$value;
        return $row;
    }
}
