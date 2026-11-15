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
            if ($key==='q_mode'||$key==='context_equals') continue;
            if (isset(LogQuery::LIST_FILTERS[$key])) {
                $values=json_decode($value,true,16,JSON_THROW_ON_ERROR);
                $column=match($key) {'levels'=>'level','sources'=>'source','source_ids'=>'source_id','services'=>'service','hosts'=>'host','trace_ids'=>'trace_id','request_ids'=>'request_id','tags_all','tags_any'=>'tags'};
                $placeholders=[];
                foreach ($values as $i=>$item) {
                    $name=$key.'_'.$i; $params[$name]=$item;
                    $placeholders[]=$key==='source_ids'?'CAST(:'.$name.' AS uuid)':'CAST(:'.$name.' AS text)';
                }
                $list=implode(',',$placeholders);
                if ($key==='sources') {
                    // Resolve slugs through the project/source registry and use the
                    // existing (project_id,source_id,occurred_at,id) index.
                    $parts[]='source_id IN (SELECT id FROM sources WHERE project_id=CAST(:sources_project AS uuid) AND slug IN ('.$list.'))';
                    $params['sources_project']=$query->filters['project_id'];
                } elseif ($column==='tags') $parts[]='tags '.($key==='tags_all'?'@>':'&&').' ARRAY['.$list.']';
                else $parts[]=$column.' IN ('.$list.')';
                continue;
            }
            if ($key==='context_path') {
                $path='(context #> ARRAY(SELECT jsonb_array_elements_text(CAST(:context_path AS jsonb))))';
                $parts[]=isset($query->filters['context_equals'])?$path.' = CAST(:context_equals AS jsonb)':$path.' IS NOT NULL';
                $params[$key]=$value;
                if (isset($query->filters['context_equals'])) $params['context_equals']=$query->filters['context_equals'];
                continue;
            }
            $parts[]=match($key) {
                'project_id','source_id'=>"$key = CAST(:$key AS uuid)",
                'source'=>'source_id IN (SELECT id FROM sources WHERE project_id=CAST(:source_project AS uuid) AND slug=:source)',
                'level','service','host','trace_id','request_id'=>"$key = :$key",
                'from'=>'occurred_at >= CAST(:from AS timestamptz)',
                'to'=>'occurred_at < CAST(:to AS timestamptz)',
                'q'=>"search_vector @@ {$query->textFunction()}('simple', :q)",
                'tag'=>'tags @> ARRAY[CAST(:tag AS text)]',
                'context'=>'context @> CAST(:context AS jsonb)',
                default=>throw new \LogicException('Unexpected filter.'),
            };
            $params[$key]=$value;
            if ($key==='source') $params['source_project']=$query->filters['project_id'];
        }
        return [implode(' AND ',$parts),$params];
    }
    /** Internal v1 keyset statement. HTTP uses the byte-bounded explorerStatement. */
    public static function pageStatement(LogQuery $query): array
    {
        if ($query->consistency!=='live') throw new \LogicException('Snapshot requires the signed explorer.');
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
    /** HTTP navigation uses signed state; internal CLI iteration retains its private v1 cursor. */
    public const PAGE_BYTES=2097152;
    public static function explorerStatement(LogQuery $query,array $state): array
    {
        [$where,$params]=self::predicate($query);
        if ($state['w']!==null) {
            $where.=' AND received_at <= CAST(:watermark AS timestamptz)';
            $params['watermark']=$state['w'];
        }
        $newer=$state['d']==='newer'; $order=$newer?'ASC':'DESC';
        if ($state['t']!==null) {
            $where.=' AND (occurred_at,id) '.($newer?'>':'<').' (CAST(:cursor_time AS timestamptz),CAST(:cursor_id AS uuid))';
            $params+=['cursor_time'=>$state['t'],'cursor_id'=>$state['id']];
        }
        // Bound actual text crossing the database/PHP boundary, including legacy JSONB.
        // Candidate selection still uses the indexed keyset order; at most limit+1 rows
        // are serialized. Return metadata for overflow rows, never their full payload.
        $limit=$query->limit; $bytes=self::PAGE_BYTES;
        $sql='WITH candidates AS MATERIALIZED (SELECT '.self::COLUMNS.', occurred_at FROM log_events WHERE '.$where.' ORDER BY occurred_at '.$order.',id '.$order.' LIMIT '.($limit+1).'), '
            ."encoded AS MATERIALIZED (SELECT id, timestamp, occurred_at, (to_jsonb(c)-'occurred_at')::text AS payload FROM candidates c), "
            .'sized AS (SELECT *, row_number() OVER (ORDER BY occurred_at '.$order.',id '.$order.') AS rn, sum(octet_length(payload)) OVER (ORDER BY occurred_at '.$order.',id '.$order.') AS bytes FROM encoded) '
            .'SELECT id,timestamp, CASE WHEN rn<='.$limit.' AND bytes<='.$bytes.' THEN payload END AS payload FROM sized ORDER BY occurred_at '.$order.',id '.$order;
        return [$sql,$params];
    }
    public function explore(LogQuery $query,PageCursor $signer,array $state,?callable $budget=null): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Explorer requires a read transaction.');
        if ($query->consistency==='snapshot' && $state['w']===null) {
            // Drain in-flight repository INSERTs before taking the watermark. New
            // writers wait until this short read transaction commits.
            if ($budget!==null) $budget();
            $this->paginationFence($query->filters['project_id'],true);
            if ($budget!==null) $budget();
            $state['w']=(string)$this->pdo->query("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")->fetchColumn();
            $state['w']=LogQuery::timestamp($state['w']);
        }
        if ($budget!==null) $budget();
        [$sql,$params]=self::explorerStatement($query,$state);
        $rows=$this->sql($sql,$params)->fetchAll(PDO::FETCH_ASSOC);
        return self::explorerPage($query,$signer,$state,$rows);
    }
    private function paginationFence(string $project,bool $exclusive=false): void
    {
        $fn=$exclusive?'pg_advisory_xact_lock':'pg_advisory_xact_lock_shared';
        $this->sql("SELECT $fn(hashtextextended('cajeerlogs:pagination:' || current_schema() || ':' || CAST(:project AS text),0))",['project'=>LogQuery::uuid($project)])->closeCursor();
    }
    /** Pure page assembly; also exercised against synthetic boundary rows. */
    public static function explorerPage(LogQuery $query,PageCursor $signer,array $state,array $rows): array
    {
        $items=[]; $more=false; $bytes=0;
        foreach ($rows as $row) {
            if ($row['payload']===null || count($items)>=$query->limit) { $more=true; break; }
            // Preserve historical response types (including large integer strings).
            $item=StoredLogJson::decode($row['payload']);
            $item=(array)$item;
            $size=strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))+1;
            if ($bytes+$size>self::PAGE_BYTES) { $more=true; break; }
            $items[]=$item; $bytes+=$size;
        }
        if ($more && !$items) throw new HttpException(413,'payload_too_large','A log event exceeds the page byte budget. Read the individual event.');
        $newer=$state['d']==='newer';
        if ($newer) $items=array_reverse($items);
        $first=$items[0]??null; $last=$items[count($items)-1]??null;
        $cursor=static fn(array $row,string $direction)=>$signer->issue($query,array_replace($state,['d'=>$direction,'t'=>$row['timestamp'],'id'=>$row['id']]));
        return ['items'=>$items,
            'next_cursor'=>$last && ($newer?$state['t']!==null:$more)?$cursor($last,'older'):null,
            'prev_cursor'=>$first && ($newer?$more:$state['t']!==null)?$cursor($first,'newer'):null,
            'page_cursor'=>$signer->issue($query,$state), 'consistency'=>$query->consistency,
            'snapshot_at'=>$state['w'],'cursor_expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$state['exp']),
            'page_digest'=>hash('sha256',json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
    }
    public function find(LogQuery $query,string $id): ?array
    {
        [$where,$params]=self::predicate($query);
        // Limit historical oversized rows before transferring them to PHP. A single
        // materialized candidate preserves a consistent result and distinguishes
        // oversized from absent/unauthorized events without another query.
        $sql='WITH candidate AS MATERIALIZED (SELECT '.self::COLUMNS.' FROM log_events WHERE '.$where.' AND id=CAST(:event_id AS uuid)), '
            .'encoded AS MATERIALIZED (SELECT to_jsonb(c)::text AS payload FROM candidate c) '
            .'SELECT CASE WHEN octet_length(payload)<='.StoredLogJson::DETAIL_BYTES.' THEN payload END AS payload FROM encoded';
        $row=$this->sql($sql,$params+['event_id'=>$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['payload']===null) StoredLogJson::tooLarge();
        $item=(array)StoredLogJson::decode($row['payload']);
        if (strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>StoredLogJson::DETAIL_BYTES) StoredLogJson::tooLarge();
        return $item;
    }
    public function statistics(LogQuery $query): array
    {
        [$where,$params]=self::predicate($query);
        // All dimensions use the same bounded, permission-scoped PostgreSQL snapshot.
        // Only validated intervals reach this fixed SQL expression.
        $bucket=match($query->interval) {
            '5min'=>"date_bin(interval '5 minutes',occurred_at AT TIME ZONE 'UTC',timestamp '2000-01-01')",
            'minute','hour','day'=>"date_trunc('{$query->interval}',occurred_at AT TIME ZONE 'UTC')",
        };
        $sql=<<<SQL
            WITH filtered AS (
                SELECT level,source_id,source,service,host,$bucket AS bucket
                FROM log_events WHERE $where
            ), grouped AS (
                SELECT level,source_id,source,service,host,bucket,count(*) AS n,
                    count(*) FILTER (WHERE level IN ('error','critical','alert','emergency')) AS errors,
                    CASE WHEN GROUPING(level)=0 THEN 'level' WHEN GROUPING(source_id)=0 THEN 'source'
                         WHEN GROUPING(service)=0 THEN 'service' WHEN GROUPING(host)=0 THEN 'host'
                         WHEN GROUPING(bucket)=0 THEN 'bucket' ELSE 'total' END AS kind
                FROM filtered GROUP BY GROUPING SETS ((),(level),(source_id,source),(bucket),(service),(host))
            ), ranked AS (
                SELECT *,row_number() OVER(PARTITION BY kind ORDER BY n DESC,source_id,source,service,host,level,bucket) AS rn,
                    count(*) OVER(PARTITION BY kind) AS group_count FROM grouped
                WHERE NOT (kind='service' AND (service IS NULL OR service=''))
                  AND NOT (kind='host' AND (host IS NULL OR host=''))
            )
            SELECT kind,level,source_id,source,service,host,to_char(bucket,'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS bucket,n,errors,group_count
            FROM ranked WHERE (kind NOT IN ('source','service','host')) OR (kind='source' AND rn<=100) OR (kind IN ('service','host') AND rn<=10)
            ORDER BY kind,bucket,level,n DESC,source_id,source,service,host
            SQL;
        $result=['total'=>0,'by_level'=>array_fill_keys(LogQuery::LEVELS,0),'by_source'=>[],'source_count'=>0,'sources_truncated'=>false,'by_service'=>[],'by_host'=>[],'buckets'=>[],'interval'=>$query->interval,'from'=>$query->filters['from'],'to'=>$query->filters['to']];
        foreach ($this->sql($sql,$params) as $row) {
            $n=(int)$row['n'];
            switch ($row['kind']) {
                case 'total': $result['total']=$n; break;
                case 'level': $result['by_level'][$row['level']]=$n; break;
                case 'source': $result['by_source'][]=['source_id'=>$row['source_id'],'source'=>$row['source'],'count'=>$n]; $result['source_count']=(int)$row['group_count']; $result['sources_truncated']=$result['source_count']>100; break;
                case 'service': $result['by_service'][]=['service'=>$row['service'],'count'=>$n]; break;
                case 'host': $result['by_host'][]=['host'=>$row['host'],'count'=>$n]; break;
                case 'bucket': $result['buckets'][]=['timestamp'=>$row['bucket'],'count'=>$n,'errors'=>(int)$row['errors']]; break;
            }
        }
        return $result;
    }
    private static function decode(array $row,bool $historicalRewrite=false): array
    {
        $row['context']=$historicalRewrite?json_decode($row['context'],false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING):StoredLogJson::decode($row['context']);
        $row['tags']=json_decode($row['tags'],true,32,JSON_THROW_ON_ERROR);
        return $row;
    }

    /** Resolve registered sources in one query, never using client-supplied UUIDs. */
    public function resolveSources(array $events,string $project,?string $tokenSource): array
    {
        $slugs=[];
        foreach ($events as $event) { $slugs[$event->source]=true; }
        $sql='SELECT id,slug FROM sources WHERE project_id=CAST(:project AS uuid) AND slug IN (SELECT jsonb_array_elements_text(CAST(:slugs AS jsonb)))';
        $params=['project'=>LogQuery::uuid($project),'slugs'=>json_encode(array_map('strval',array_keys($slugs)),JSON_THROW_ON_ERROR)];
        if ($tokenSource!==null) { $sql.=' AND id=CAST(:source AS uuid)'; $params['source']=LogQuery::uuid($tokenSource); }
        $stmt=$this->sql($sql,$params);
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
    public function storeBatch(array $events, string $project, array $sources, string $token,int $version=IngestValidator::VERSION): array
    {
        ++self::$metrics['batch_attempts'];
        try {
            $ids=$this->insertBatch($events,$project,$sources,$token,$version);
            self::$metrics['rows_returned']+=count($ids);
            return $ids;
        } catch (\Throwable $e) { ++self::$metrics['failed_batches']; throw $e; }
    }
    private function insertBatch(array $events,string $project,array $sources,string $token,int $version): array
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Log writes require a transaction.'); }
        $events=IngestBatch::normalize($events,IngestBatch::SQL_BYTES,$version);
        $events=(new RedactionPolicies($this->pdo))->apply($events,$project,$sources,$version);
        [$ids,$body]=self::encodeBatch($events,$sources,$version);
        $project=LogQuery::uuid($project); $token=LogQuery::uuid($token);
        $this->paginationFence($project);
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
                    AND (t.source_id IS NULL OR t.source_id=s.id) AND jsonb_typeof(t.scopes)='array' AND t.scopes @> '["logs:write"]'::jsonb
                    AND t.scopes <@ '["logs:read","logs:write"]'::jsonb
                    AND jsonb_array_length(CASE WHEN jsonb_typeof(t.scopes)='array' THEN t.scopes ELSE '[]'::jsonb END) =
                        CASE WHEN t.scopes @> '["logs:read"]'::jsonb THEN 2 ELSE 1 END
            )
            INSERT INTO log_events(id,occurred_at,source,service,host,level,message,context,trace_id,request_id,tags,project_id,source_id,ingest_token_id,received_at)
            SELECT id,occurred_at,source,service,host,level,message,context,trace_id,request_id,
                ARRAY(SELECT jsonb_array_elements_text(e.tags)),project_id,source_id,token_id,clock_timestamp()
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
    public static function encodeBatch(array $events,array $sources,int $version=IngestValidator::VERSION): array
    {
        $events=IngestBatch::normalize($events,IngestBatch::SQL_BYTES,$version);
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

    /** Host-only streaming export in a caller-owned repeatable-read transaction.
     * Position is request-local, never exposed as an unsigned HTTP cursor. */
    public function iterateBounded(LogQuery $query): \Generator
    {
        if (!$this->pdo->inTransaction() || $query->cursor!==null || $query->consistency!=='live') throw new \LogicException('Bounded export requires a fresh query and an owned transaction.');
        $signer=new PageCursor(random_bytes(32),'cli-export');
        $state=['d'=>'older','t'=>null,'id'=>null,'w'=>null,'exp'=>time()+PageCursor::TTL];
        do {
            $page=$this->explore($query,$signer,$state);
            foreach ($page['items'] as $item) yield $item;
            if ($page['next_cursor']===null) break;
            $last=$page['items'][count($page['items'])-1];
            $state['t']=$last['timestamp']; $state['id']=$last['id'];
        } while (true);
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
        $normalized=(new RedactionPolicies($this->pdo))->apply([$normalized],$project,[$target=>$source])[0];
        $this->sql('UPDATE log_events SET project_id=:project,source_id=:source,level=:level,message=:message,context=CAST(:context AS jsonb),service=:service,host=:host,trace_id=:trace,request_id=:request,tags=ARRAY(SELECT jsonb_array_elements_text(CAST(:tags AS jsonb))) WHERE id=:id',[
            'project'=>$project,'source'=>$source,'level'=>$normalized->level,'message'=>$normalized->message,'context'=>json_encode($normalized->context,JSON_THROW_ON_ERROR),
            'service'=>$normalized->service,'host'=>$normalized->host,'trace'=>$normalized->traceId,'request'=>$normalized->requestId,'tags'=>json_encode($normalized->tags,JSON_THROW_ON_ERROR),'id'=>$id]);
        (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('logs.legacy_adopted',['project_id'=>$project,'source_id'=>$source],null,$id);
    }

    /** A history rewrite only changes masked context leaves, preserving unmodified
     * PostgreSQL numeric precision and JSON types even for old arbitrary decimals. */
    public static function contextPatches(mixed $before,mixed $after,array $path=[]): array
    {
        if (($before instanceof \stdClass && $after instanceof \stdClass) || (is_array($before) && is_array($after))) {
            $out=[]; foreach ($before as $k=>$v) {
                $next=$after instanceof \stdClass?$after->{(string)$k}:$after[$k];
                foreach (self::contextPatches($v,$next,[...$path,(string)$k]) as $patch) $out[]=$patch;
            }
            return $out;
        }
        return $before===$after?[]:[['path'=>$path,'value'=>$after]];
    }

    /** Explicit bounded historical rewrite. No event contents leave this method. */
    public function redactHistory(string $project,?string $after,int $limit,bool $apply): array
    {
        $this->requireTransaction(); $project=LogQuery::uuid($project);
        if ($limit<1 || $limit>50) throw new DatabaseException('Invalid history batch limit.');
        $policies=new RedactionPolicies($this->pdo); $policies->lock(); $policies->get($project);
        $params=['project'=>$project]; $where='project_id=:project AND source_id IS NOT NULL';
        if ($after!==null) { $params['after']=LogQuery::uuid($after); $where.=' AND id>:after'; }
        // Never fetch unbounded legacy/corrupted rows; fail the page rather than skipping them.
        $columns=str_replace('message,context,trace_id','CASE WHEN octet_length(message)<=65536 THEN message END AS message,CASE WHEN octet_length(context::text)<=262144 THEN context END AS context,trace_id',self::COLUMNS);
        $rows=$this->sql('SELECT '.$columns.' FROM log_events WHERE '.$where.' ORDER BY id LIMIT '.($limit+1).($apply?' FOR UPDATE':''),$params)->fetchAll();
        $more=count($rows)>$limit; if ($more) array_pop($rows);
        $changed=0; $last=null;
        foreach ($rows as $row) {
            $last=$row['id'];
            if ($row['context']===null || $row['message']===null) throw new DatabaseException('Historical event exceeds safe redaction size.');
            $r=self::decode($row,true);
            $event=new IngestEvent($r['source'],$r['level'],$r['message'],$r['context'],$r['timestamp'],$r['service'],$r['host'],$r['trace_id'],$r['request_id'],$r['tags']);
            $masked=$policies->apply([$event],$project,[$r['source']=>$r['source_id']],1)[0];
            if (json_encode($event,JSON_THROW_ON_ERROR)===json_encode($masked,JSON_THROW_ON_ERROR)) continue;
            ++$changed;
            if (!$apply) continue;
            $patches=self::contextPatches($event->context,$masked->context);
            // Bounded SQL work; report failure rather than leaving a partly masked event.
            if (count($patches)>2048) throw new DatabaseException('Historical event exceeds safe redaction patch count.');
            $this->sql(<<<'SQL'
                UPDATE log_events l SET message=:message,
                    context=(WITH RECURSIVE changes AS (
                        SELECT value,ordinality FROM jsonb_array_elements(CAST(:patch AS jsonb)) WITH ORDINALITY
                    ), rewritten(n,ctx) AS (
                        SELECT 0::bigint,l.context
                        UNION ALL
                        SELECT c.ordinality,jsonb_set(r.ctx,ARRAY(SELECT jsonb_array_elements_text(c.value->'path')),c.value->'value',false)
                        FROM rewritten r JOIN changes c ON c.ordinality=r.n+1
                    ) SELECT ctx FROM rewritten WHERE n=(SELECT count(*) FROM changes)),
                    service=:service,host=:host,trace_id=:trace,request_id=:request,tags=ARRAY(SELECT jsonb_array_elements_text(CAST(:tags AS jsonb)))
                WHERE l.id=:id AND l.project_id=:project
                SQL,[
                'message'=>$masked->message,'patch'=>json_encode($patches,JSON_THROW_ON_ERROR),'service'=>$masked->service,'host'=>$masked->host,'trace'=>$masked->traceId,'request'=>$masked->requestId,'tags'=>json_encode($masked->tags,JSON_THROW_ON_ERROR),'id'=>$last,'project'=>$project]);
        }
        if ($apply) (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('logs.history_redacted',['project_id'=>$project,'scanned'=>count($rows),'changed'=>$changed,'after'=>$after,'last_id'=>$last],null,$project);
        return ['dry_run'=>!$apply,'scanned'=>count($rows),'changed'=>$changed,'next_cursor'=>$more?$last:null];
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

    public function saveReceipt(string $token,string $keyHash,string $requestHash,array $ids,?string $digest=null,int $retention=DeliveryRepository::RETENTION_SECONDS): void
    {
        $this->requireTransaction(); self::validateReceiptIds($ids);
        if ($digest!==null) {
            $this->sql('DELETE FROM ingest_receipts WHERE token_id=:token AND key_hash=:key AND expires_at<=clock_timestamp()',['token'=>$token,'key'=>$keyHash]);
            $this->sql("INSERT INTO ingest_receipts(token_id,key_hash,request_hash,event_ids,expires_at,delivery_digest) VALUES (:token,:key,:hash,CAST(:ids AS jsonb),clock_timestamp()+CAST(:ttl AS integer)*interval '1 second',:digest)", ['token'=>$token,'key'=>$keyHash,'hash'=>$requestHash,'ids'=>json_encode($ids,JSON_THROW_ON_ERROR),'digest'=>$digest,'ttl'=>DeliveryRepository::retention($retention)]);
            return;
        }
        $this->sql("INSERT INTO ingest_receipts(token_id,key_hash,request_hash,event_ids,expires_at) VALUES (:token,:key,:hash,CAST(:ids AS jsonb),clock_timestamp()+interval '24 hours')",
            ['token'=>$token,'key'=>$keyHash,'hash'=>$requestHash,'ids'=>json_encode($ids,JSON_THROW_ON_ERROR)]);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Ingest receipt and nonce operations require a transaction.');
    }
    public static function validateReceiptIds(mixed $ids): void
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids)<1 || count($ids)>IngestValidator::MAX_BATCH) throw new DatabaseException('Invalid stored ingest receipt.');
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$id)) throw new DatabaseException('Invalid stored ingest receipt.');
        }
        if (count(array_unique($ids))!==count($ids)) throw new DatabaseException('Invalid stored ingest receipt.');
    }

    private function sql(string $sql,array $params): \PDOStatement { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt; }
}
