<?php
declare(strict_types=1);
namespace Cajeer\Logs\Search;

use Cajeer\Logs\Log\LogQuery;
use PDO;

/** PostgreSQL-backed explorer; every query is constrained by project and effective source. */
final class PostgresFullTextSearchEngine
{
    private const COLUMNS="id,project_id,source_id,source,service,host,level,message,context,trace_id,request_id,array_to_json(tags) AS tags, to_char(occurred_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS timestamp, to_char(received_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS received_at";
    public function __construct(private readonly PDO $pdo) {}

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
    public function page(LogQuery $query): array
    {
        [$where,$params]=self::predicate($query);
        if (($pos=$query->position())!==null) {
            $where.=' AND (occurred_at,id) < (CAST(:cursor_time AS timestamptz),CAST(:cursor_id AS uuid))';
            $params+=['cursor_time'=>$pos['t'],'cursor_id'=>$pos['id']];
        }
        $rows=$this->sql('SELECT '.self::COLUMNS.' FROM log_events WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT '.($query->limit+1),$params)->fetchAll();
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
    private function sql(string $sql,array $params): \PDOStatement
    {
        $s=$this->pdo->prepare($sql); $s->execute($params); return $s;
    }
}
