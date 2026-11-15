<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use PDO;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;

final class LogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    private static array $metrics=['batch_attempts'=>0,'failed_batches'=>0,'rows_returned'=>0,'insert_seconds'=>0.0];
    public static function metrics(): array { return self::$metrics; }

    private const COLUMNS="id,project_id,source_id,source,service,host,level,message,context,trace_id,request_id,array_to_json(tags) AS tags, to_char(occurred_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS timestamp, to_char(received_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS received_at";

    /** @return array{0:string,1:array} */
    public static function predicate(LogQuery $query): array
    {
        $parts=['source_id IS NOT NULL']; $params=[];
        foreach ($query->filters as $key=>$value) {
            $parts[]=match($key) {
                'project_id','source_id'=>"$key = CAST(:$key AS uuid)",
                'source','level','service','host','trace_id','request_id'=>"$key = :$key",
                'from'=>'occurred_at >= CAST(:from AS timestamptz)',
                'to'=>'occurred_at < CAST(:to AS timestamptz)',
                'q'=>"search_vector @@ websearch_to_tsquery('simple', :q)",
                'tag'=>'tags @> ARRAY[CAST(:tag AS text)]',
                'context'=>'context @> CAST(:context AS jsonb)',
                default=>throw new \LogicException('Unexpected filter.'),
            };
            $params[$key]=$value;
        }
        return [implode(' AND ',$parts),$params];
    }
    /** The same query is used for runtime reads and read-only EXPLAIN diagnostics. */
    public static function pageStatement(LogQuery $query): array
    {
        [$where,$params]=self::predicate($query);
        if (($pos=$query->position())!==null) {
            $where.=' AND (occurred_at,id) < (CAST(:cursor_time AS timestamptz),CAST(:cursor_id AS uuid))';
            $params+=['cursor_time'=>$pos['t'],'cursor_id'=>$pos['id']];
        }
        return ['SELECT '.self::COLUMNS.' FROM log_events WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT '.($query->limit+1),$params];
    }
    public function page(LogQuery $query): array
    {
        [$sql,$params]=self::pageStatement($query);
        $rows=$this->sql($sql,$params)->fetchAll();
        $more=count($rows)>$query->limit;
        if ($more) { array_pop($rows); }
        $last=$more?end($rows):null;
        return ['items'=>array_map(self::decode(...),$rows),'next_cursor'=>$last?$query->next($last['timestamp'],$last['id']):null];
    }
    public function find(LogQuery $query,string $id): ?array
    {
        [$where,$params]=self::predicate($query);
        $row=$this->sql('SELECT '.self::COLUMNS.' FROM log_events WHERE '.$where.' AND id=CAST(:event_id AS uuid)',$params+['event_id'=>$id])->fetch();
        return $row?self::decode($row):null;
    }
    public function statistics(LogQuery $query): array
    {
        [$where,$params]=self::predicate($query);
        // One statement = one PostgreSQL snapshot. Only the 100 largest source groups are returned.
        $sql=<<<SQL
            WITH filtered AS (
                SELECT level,source_id,source,date_trunc('{$query->interval}',occurred_at AT TIME ZONE 'UTC') AS bucket
                FROM log_events WHERE $where
            ), grouped AS (
                SELECT level,source_id,source,bucket,count(*) AS n,
                    CASE WHEN GROUPING(level)=0 THEN 'level' WHEN GROUPING(source_id)=0 THEN 'source'
                         WHEN GROUPING(bucket)=0 THEN 'bucket' ELSE 'total' END AS kind
                FROM filtered GROUP BY GROUPING SETS ((),(level),(source_id,source),(bucket))
            ), ranked AS (
                SELECT *,row_number() OVER(PARTITION BY kind ORDER BY n DESC,source_id,source,level,bucket) AS rn,
                    count(*) OVER(PARTITION BY kind) AS group_count FROM grouped
            )
            SELECT kind,level,source_id,source,to_char(bucket,'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS bucket,n,group_count
            FROM ranked WHERE kind<>'source' OR rn<=100 ORDER BY kind,bucket,level,n DESC,source_id,source
            SQL;
        $result=['total'=>0,'by_level'=>array_fill_keys(LogQuery::LEVELS,0),'by_source'=>[],'sources_truncated'=>false,'buckets'=>[],'interval'=>$query->interval,'from'=>$query->filters['from'],'to'=>$query->filters['to']];
        foreach ($this->sql($sql,$params) as $row) {
            $n=(int)$row['n'];
            switch ($row['kind']) {
                case 'total': $result['total']=$n; break;
                case 'level': $result['by_level'][$row['level']]=$n; break;
                case 'source': $result['by_source'][]=['source_id'=>$row['source_id'],'source'=>$row['source'],'count'=>$n]; $result['sources_truncated']=(int)$row['group_count']>100; break;
                case 'bucket': $result['buckets'][]=['timestamp'=>$row['bucket'],'count'=>$n]; break;
            }
        }
        return $result;
    }
    private static function decode(array $row): array
    {
        $row['context']=json_decode($row['context'],false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        $row['tags']=json_decode($row['tags'],true,32,JSON_THROW_ON_ERROR);
        return $row;
    }

    /** Resolve registered sources in one query, never using client-supplied UUIDs. */
    public function resolveSources(array $events,string $project,?string $tokenSource): array
    {
        $slugs=[];
        foreach ($events as $event) { $slugs[$event->source]=true; }
        $stmt=$this->sql('SELECT id,slug FROM sources WHERE project_id=CAST(:project AS uuid) AND slug IN (SELECT jsonb_array_elements_text(CAST(:slugs AS jsonb)))',
            ['project'=>LogQuery::uuid($project),'slugs'=>json_encode(array_map('strval',array_keys($slugs)),JSON_THROW_ON_ERROR)]);
        $map=[];
        foreach ($stmt as $row) {
            if ($tokenSource!==null && $row['id']!==$tokenSource) { throw new HttpException(403,'forbidden','Access denied.'); }
            $map[$row['slug']]=$row['id'];
        }
        $stmt->closeCursor();
        if (count($map)!==count($slugs)) { throw new HttpException(403,'forbidden','Access denied.'); }
        return $map;
    }

    /** @param list<IngestEvent> $events @param array<string,string> $sources @return list<string> */
    public function storeBatch(array $events, string $project, array $sources, string $token): array
    {
        ++self::$metrics['batch_attempts'];
        try {
            $ids=$this->insertBatch($events,$project,$sources,$token);
            self::$metrics['rows_returned']+=count($ids);
            return $ids;
        } catch (\Throwable $e) { ++self::$metrics['failed_batches']; throw $e; }
    }
    private function insertBatch(array $events,string $project,array $sources,string $token): array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Log writes require a transaction.'); }
        [$ids,$body]=self::encodeBatch($events,$sources);
        $project=LogQuery::uuid($project); $token=LogQuery::uuid($token);
        // A single statement admits the entire batch or no rows. Queue payloads cannot
        // override source/project/token attribution, even if corrupted on disk.
        $stmt=$this->pdo->prepare(<<<'SQL'
            WITH batch_input AS MATERIALIZED (
                SELECT * FROM jsonb_to_recordset(CAST(:events AS jsonb)) AS e(id uuid,occurred_at timestamptz,source text,service text,host text,
                    level text,message text,context jsonb,trace_id text,request_id text,tags jsonb,source_id uuid)
            ), batch_scope AS (SELECT CAST(:project AS uuid) AS project_id, CAST(:token AS uuid) AS token_id),
            eligible AS MATERIALIZED (
                SELECT e.*,s.project_id,t.id AS token_id FROM batch_input e
                CROSS JOIN batch_scope p
                JOIN sources s ON s.id=e.source_id AND s.project_id=p.project_id AND s.slug=e.source
                JOIN api_tokens t ON t.id=p.token_id AND t.project_id=s.project_id
                    AND (t.source_id IS NULL OR t.source_id=s.id) AND jsonb_exists(t.scopes,'logs:write')
            )
            INSERT INTO log_events(id,occurred_at,source,service,host,level,message,context,trace_id,request_id,tags,project_id,source_id,ingest_token_id)
            SELECT id,occurred_at,source,service,host,level,message,context,trace_id,request_id,
                ARRAY(SELECT jsonb_array_elements_text(e.tags)),project_id,source_id,token_id
            FROM eligible e WHERE (SELECT count(*) FROM eligible)=(SELECT count(*) FROM batch_input)
            RETURNING id
            SQL);
        $start=hrtime(true);
        try {
            $stmt->execute(['events'=>$body,'project'=>$project,'token'=>$token]);
            $written=$stmt->fetchAll(PDO::FETCH_COLUMN); $stmt->closeCursor();
        } finally { self::$metrics['insert_seconds']+=(hrtime(true)-$start)/1e9; }
        if ($written===[]) { throw new HttpException(403,'forbidden','Log batch is outside its project, source or token scope.'); }
        $expected=$ids; sort($expected); sort($written);
        if ($written!==$expected) { throw new \RuntimeException('Incomplete batch insert; roll back transaction.'); }
        return $ids;
    }

    /** Bounded normalized payload, also used for jobs that have already passed HTTP validation.
     * @return array{0:list<string>,1:string} */
    public static function encodeBatch(array $events,array $sources): array
    {
        $events=IngestBatch::normalize($events);
        $sources=IngestBatch::sourceMap($events,$sources);
        // Generate UUIDs before a single multi-row INSERT, preserving receipt order.
        $ids=[];$rows=[];$size=2;
        foreach ($events as $event) {
            $sourceId=$sources[$event->source];
            $bytes=random_bytes(16);
            $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
            $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
            $hex=bin2hex($bytes);
            $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $ids[]=$id;
            $row=['id'=>$id,'occurred_at'=>$event->timestamp,'source'=>$event->source,'service'=>$event->service,'host'=>$event->host,
                'level'=>$event->level,'message'=>$event->message,'context'=>$event->context,'trace_id'=>$event->traceId,'request_id'=>$event->requestId,
                'tags'=>$event->tags,'source_id'=>$sourceId];
            $encoded=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $size+=strlen($encoded)+($rows===[]?0:1);
            if ($size>IngestBatch::SQL_BYTES) { throw new HttpException(413,'payload_too_large','Normalized log batch exceeds 4 MiB.'); }
            $rows[]=$encoded;
        }
        $body='['.implode(',',$rows).']';
        if (strlen($body)>IngestBatch::SQL_BYTES) { throw new HttpException(413,'payload_too_large','Normalized log batch exceeds 4 MiB.'); }
        return [$ids,$body];
    }

    /** Page-sized iteration with stable keyset order; caller chooses snapshot isolation. */
    public function iterate(LogQuery $query): \Generator
    {
        do {
            $page=$this->page($query);
            foreach ($page['items'] as $item) { yield $item; }
            $cursor=$page['next_cursor'];
            if ($cursor!==null) { $query=LogQuery::parse($query->filters+['limit'=>(string)$query->limit,'cursor'=>$cursor]); }
        } while ($cursor!==null);
    }

    /** Legacy rows are not automatically assigned or exposed to a project. */
    public function legacyCounts(): array
    {
        return $this->pdo->query('SELECT count(*) FILTER (WHERE project_id IS NULL AND source_id IS NULL) AS unscoped, count(*) FILTER (WHERE project_id IS NOT NULL AND source_id IS NULL) AS project_only FROM log_events WHERE project_id IS NULL OR source_id IS NULL')->fetch();
    }

    /** Bounded inspection of identifiers and routing only; no raw message/context. */
    public function legacyList(int $limit=50,?string $after=null): array
    {
        if ($limit<1 || $limit>100) throw new DatabaseException('Invalid legacy page size.');
        $params=[];$where='source_id IS NULL';
        if ($after!==null) { $where.=' AND id>CAST(:after AS uuid)'; $params['after']=LogQuery::uuid($after); }
        $rows=$this->sql('SELECT id,project_id,source,occurred_at,received_at FROM log_events WHERE '.$where.' ORDER BY id LIMIT '.($limit+1),$params)->fetchAll();
        $more=count($rows)>$limit;if ($more) array_pop($rows);
        return ['items'=>$rows,'next_after'=>$more?$rows[count($rows)-1]['id']:null];
    }

    /** Explicit operator-approved adoption of one historical row, never bulk guessing by slug. */
    public function adoptLegacy(string $id,string $project,string $source): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Legacy adoption requires a transaction.'); }
        $id=LogQuery::uuid($id); $project=LogQuery::uuid($project); $source=LogQuery::uuid($source);
        $target=$this->sql('SELECT slug FROM sources WHERE id=:id AND project_id=:project FOR SHARE',['id'=>$source,'project'=>$project])->fetchColumn();
        if (!is_string($target)) { throw new \Cajeer\Logs\Database\DatabaseException('Target source does not belong to project.'); }
        $row=$this->sql('SELECT '.self::COLUMNS.' FROM log_events WHERE id=:id AND source_id IS NULL AND (project_id IS NULL OR project_id=:project) AND ingest_token_id IS NULL FOR UPDATE',['id'=>$id,'project'=>$project])->fetch();
        if (!$row || $row['source']!==$target) { throw new \Cajeer\Logs\Database\DatabaseException('Legacy event is unavailable or source slug differs.'); }
        $event=self::decode($row);
        $data=array_intersect_key($event,array_flip(['timestamp','source','service','host','level','message','context','trace_id','request_id','tags']));
        $request=new \Cajeer\Logs\Http\Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode($data,JSON_THROW_ON_ERROR));
        $normalized=(new IngestValidator())->parse($request)[0];
        $this->sql('UPDATE log_events SET project_id=:project,source_id=:source,level=:level,message=:message,context=CAST(:context AS jsonb),service=:service,host=:host,trace_id=:trace,request_id=:request,tags=ARRAY(SELECT jsonb_array_elements_text(CAST(:tags AS jsonb))) WHERE id=:id',[
            'project'=>$project,'source'=>$source,'level'=>$normalized->level,'message'=>$normalized->message,'context'=>json_encode($normalized->context,JSON_THROW_ON_ERROR),
            'service'=>$normalized->service,'host'=>$normalized->host,'trace'=>$normalized->traceId,'request'=>$normalized->requestId,'tags'=>json_encode($normalized->tags,JSON_THROW_ON_ERROR),'id'=>$id]);
        (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('logs.legacy_adopted',['project_id'=>$project,'source_id'=>$source],null,$id);
    }

    /** Called inside the same transaction as log storage; the authenticated token row is locked. */
    public function claimNonce(string $token,string $nonce): void
    {
        $this->requireTransaction();
        $params=['token'=>$token,'nonce'=>hash('sha256',$nonce)];
        $this->sql('DELETE FROM ingest_nonces WHERE token_id=:token AND nonce_hash=:nonce AND expires_at<=clock_timestamp()',$params);
        $s=$this->sql("INSERT INTO ingest_nonces(token_id,nonce_hash,expires_at) VALUES (:token,:nonce,clock_timestamp()+interval '11 minutes') ON CONFLICT DO NOTHING",$params);
        if ($s->rowCount()!==1) { throw new HttpException(409,'replay_detected','Signature nonce has already been used.'); }
    }

    /** @return list<string>|null */
    public function receipt(string $token,string $keyHash,string $requestHash): ?array
    {
        $this->requireTransaction();
        $params=['token'=>$token,'key'=>$keyHash];
        $this->sql('DELETE FROM ingest_receipts WHERE token_id=:token AND key_hash=:key AND expires_at<=clock_timestamp()',$params);
        $row=$this->sql('SELECT request_hash,event_ids FROM ingest_receipts WHERE token_id=:token AND key_hash=:key',$params)->fetch();
        if (!$row) { return null; }
        if (!hash_equals($row['request_hash'],$requestHash)) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key was used for a different request.'); }
        $ids=json_decode($row['event_ids'],true,32,JSON_THROW_ON_ERROR);
        self::validateReceiptIds($ids);
        return $ids;
    }

    public function saveReceipt(string $token,string $keyHash,string $requestHash,array $ids): void
    {
        $this->requireTransaction(); self::validateReceiptIds($ids);
        $this->sql("INSERT INTO ingest_receipts(token_id,key_hash,request_hash,event_ids,expires_at) VALUES (:token,:key,:hash,CAST(:ids AS jsonb),clock_timestamp()+interval '24 hours')",
            ['token'=>$token,'key'=>$keyHash,'hash'=>$requestHash,'ids'=>json_encode($ids,JSON_THROW_ON_ERROR)]);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Ingest receipt and nonce operations require a transaction.');
    }
    private static function validateReceiptIds(mixed $ids): void
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids)<1 || count($ids)>IngestValidator::MAX_BATCH) throw new DatabaseException('Invalid stored ingest receipt.');
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$id)) throw new DatabaseException('Invalid stored ingest receipt.');
        }
        if (count(array_unique($ids))!==count($ids)) throw new DatabaseException('Invalid stored ingest receipt.');
    }

    private function sql(string $sql,array $params): \PDOStatement { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt; }
}
