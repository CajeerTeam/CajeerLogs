<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

use Cajeer\Logs\Log\LogQuery;

/** Live ClickHouse ingestion and project-scoped analytics; no implicit dual-write. */
final class ClickHouseAnalyticsStore implements AnalyticsStoreInterface
{
    public function __construct(private readonly ClickHouseHttpClient $http) {}

    public function record(array $event): void { $this->recordBatch([$event]); }

    public function recordBatch(array $events): void
    {
        if ($events===[] || count($events)>100) throw new \InvalidArgumentException('Expected 1..100 events.');
        $columns=['id','project_id','source_id','occurred_at','received_at','source','service','host','level','message','context','trace_id','request_id','tags'];
        $rows=[];
        foreach ($events as $event) {
            foreach (['id','project_id','source_id'] as $key) $event[$key]=self::uuid($event[$key]??null);
            foreach (['occurred_at','received_at'] as $key) $event[$key]=self::timestamp($event[$key]??($key==='occurred_at'?($event['timestamp']??null):null));
            foreach (['source','service','host','level','message','trace_id','request_id'] as $key) {
                if (!isset($event[$key])) $event[$key]='';
                if (!is_string($event[$key]) || strlen($event[$key])>($key==='message'?65536:512)) throw new \InvalidArgumentException('Invalid event field: '.$key);
            }
            if (!in_array($event['level'],LogQuery::LEVELS,true)) throw new \InvalidArgumentException('Invalid log level.');
            $context=$event['context']??(object)[];
            if (is_string($context)) $context=json_decode($context,false,32,JSON_THROW_ON_ERROR);
            if (!is_array($context) && !$context instanceof \stdClass) throw new \InvalidArgumentException('Invalid JSON context.');
            $event['context']=json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $tags=$event['tags']??[];
            if (is_string($tags)) $tags=json_decode($tags,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($tags) || !array_is_list($tags) || count($tags)>32) throw new \InvalidArgumentException('Invalid tags.');
            foreach ($tags as $tag) if (!is_string($tag) || strlen($tag)>128) throw new \InvalidArgumentException('Invalid tag.');
            $event['tags']=$tags;
            $rows[]=array_intersect_key($event,array_flip($columns));
        }
        foreach ($rows as &$row) {
            // JSONEachRow default DateTime64 parser accepts canonical UTC SQL values.
            foreach (['occurred_at','received_at'] as $name) {
                $row[$name]=(new \DateTimeImmutable($row[$name]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            }
        } unset($row);
        $this->http->insert('log_events',$rows,$columns);
    }

    public function aggregate(array $filters=[]): array
    {
        // Never allow unscoped analytical reads, even for administrator CLI calls.
        if (array_diff(array_keys($filters),['project_id','source_id','from','to','interval','level','service','host'])!==[]) {
            throw new \InvalidArgumentException('Unknown analytics filter.');
        }
        $project=self::uuid($filters['project_id']??null);
        $from=self::timestamp($filters['from']??gmdate('Y-m-d\TH:i:s\Z',time()-86400));
        $to=self::timestamp($filters['to']??gmdate('Y-m-d\TH:i:s\Z'));
        if (strtotime($from)>=strtotime($to) || strtotime($to)-strtotime($from)>31*86400) throw new \InvalidArgumentException('Analytics range must be positive and <=31 days.');
        $interval=$filters['interval']??'hour';
        $bucket=match($interval){
            'minute'=>"toStartOfMinute(occurred_at)",
            '5min'=>"toStartOfFiveMinutes(occurred_at)",
            'hour'=>"toStartOfHour(occurred_at)",
            'day'=>"toStartOfDay(occurred_at)",
            default=>throw new \InvalidArgumentException('Unsupported analytics interval.'),
        };
        $params=['project_id'=>$project,'from'=>$from,'to'=>$to];
        $where='project_id={project_id:UUID} AND occurred_at>=parseDateTime64BestEffort({from:String},3,\'UTC\') AND occurred_at<parseDateTime64BestEffort({to:String},3,\'UTC\')';
        foreach (['source_id'=>'UUID','level'=>'String','service'=>'String','host'=>'String'] as $field=>$type) {
            if (!isset($filters[$field])) continue;
            $value=$field==='source_id'?self::uuid($filters[$field]):$filters[$field];
            if (!is_string($value) || strlen($value)>255) throw new \InvalidArgumentException('Invalid analytical filter.');
            if ($field==='level' && !in_array($value,LogQuery::LEVELS,true)) throw new \InvalidArgumentException('Invalid level.');
            $where.=' AND '.$field.'={'.$field.':'.$type.'}';$params[$field]=$value;
        }
        $table=$this->http->qualified('log_events');
        $result=['total'=>0,'by_level'=>array_fill_keys(LogQuery::LEVELS,0),'by_source'=>[],
            'source_count'=>0,'sources_truncated'=>false,'by_service'=>[],'by_host'=>[],'buckets'=>[],
            'interval'=>$interval,'from'=>$from,'to'=>$to];
        $overall=$this->http->select('SELECT count() AS n FROM '.$table.' WHERE '.$where,$params);
        $result['total']=(int)($overall[0]['n']??0);
        $levels=$this->http->select('SELECT level, count() AS n FROM '.$table.' WHERE '.$where.' GROUP BY level ORDER BY level LIMIT 8',$params);
        foreach ($levels as $row) if (isset($result['by_level'][$row['level']??''])) $result['by_level'][$row['level']]=(int)$row['n'];
        $sources=$this->http->select('SELECT source_id, any(source) AS source, count() AS n FROM '.$table.' WHERE '.$where.' GROUP BY source_id ORDER BY n DESC, source_id LIMIT 101',$params);
        $count=$this->http->select('SELECT uniqExact(source_id) AS n FROM '.$table.' WHERE '.$where,$params);
        $result['source_count']=(int)($count[0]['n']??0);$result['sources_truncated']=$result['source_count']>100;
        foreach (array_slice($sources,0,100) as $row) $result['by_source'][]=['source_id'=>$row['source_id'],'source'=>$row['source'],'count'=>(int)$row['n']];
        foreach (['service','host'] as $dim) {
            $rows=$this->http->select('SELECT '.$dim.', count() AS n FROM '.$table.' WHERE '.$where.' AND '.$dim."!='' GROUP BY ".$dim.' ORDER BY n DESC, '.$dim.' LIMIT 10',$params);
            foreach ($rows as $row) $result['by_'.$dim][]=[$dim=>$row[$dim],'count'=>(int)$row['n']];
        }
        $buckets=$this->http->select('SELECT formatDateTime('.$bucket.", '%Y-%m-%dT%H:%i:%S.000000Z', 'UTC') AS bucket, count() AS n, countIf(level IN ('error','critical','alert','emergency')) AS errors FROM ".$table.' WHERE '.$where.' GROUP BY bucket ORDER BY bucket ASC LIMIT 1000',$params);
        foreach ($buckets as $row) $result['buckets'][]=['timestamp'=>$row['bucket'],'count'=>(int)$row['n'],'errors'=>(int)$row['errors']];
        return $result;
    }

    public function ping(): array
    {
        $rows=$this->http->select('SELECT version() AS version');
        return ['database'=>$this->http->database(),'version'=>(string)($rows[0]['version']??'unknown')];
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',$value)
            || strtolower($value)==='00000000-0000-0000-0000-000000000000') throw new \InvalidArgumentException('Nonzero UUID required.');
        return strtolower($value);
    }
    private static function timestamp(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>40 || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D',$value)) throw new \InvalidArgumentException('Invalid UTC timestamp.');
        // ISO-8601 parsing covers seconds and variable fractional precision.
        try { $d=new \DateTimeImmutable($value); } catch (\Exception $e) { throw new \InvalidArgumentException('Invalid timestamp.',0,$e); }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
