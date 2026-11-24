<?php
declare(strict_types=1);
namespace Cajeer\Logs\AI;

use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Log\{LogQuery,LogRepository,SearchRuntime};
use PDO;

/** Bounded, one-hop investigation in a single read-only PostgreSQL snapshot. */
final class Investigation
{
    public const SEEDS=8;
    public const MAX_BYTES=60000;
    public static function validate(mixed $value): array
    {
        if (!is_array($value)||array_diff(array_keys($value),['event_id','trace_id','request_id'])||count($value)>1) self::invalid();
        foreach ($value as $key=>$id) {
            if (!is_string($id)||trim($id)===''||strlen($id)>128||!preg_match('//u',$id)||preg_match('/[\x00-\x1f\x7f]/',$id)) self::invalid();
            if ($key==='event_id') $value[$key]=LogQuery::uuid($id);
        }
        return $value;
    }
    private static function invalid(): never { throw new HttpException(422,'invalid_payload','Choose at most one event, trace or request identifier.'); }
    private static function scope(LogQuery $query): array
    {
        return array_intersect_key($query->filters,array_flip(['project_id','source_id','from','to']));
    }
    private static function projection(bool $messages): string
    {
        $columns="id,level,trace_id,request_id,to_char(occurred_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS timestamp";
        if ($messages) {
            // Never select the context object. Only known string stack fields, no arbitrary nested data.
            $columns.=",source,service,CASE WHEN octet_length(message)<=2048 THEN message END AS message";
            $paths=["context->'stack_trace'","context->'stacktrace'","context->'stackTrace'","context->'stack'","context#>'{exception,stack_trace}'","context#>'{exception,stacktrace}'","context#>'{exception,stackTrace}'","context#>'{exception,trace}'","context#>'{exception,stack}'"];
            $cases=array_map(static fn($p)=>"CASE WHEN jsonb_typeof($p)='string' THEN ($p) #>> '{}' END",$paths);
            $stack='COALESCE('.implode(',',$cases).')';
            $columns.=",CASE WHEN octet_length($stack)<=4096 THEN $stack END AS stack_trace,($stack IS NOT NULL AND octet_length($stack)>4096) AS stack_omitted";
        }
        return $columns;
    }
    public static function seedStatement(LogQuery $query,array $selection,bool $messages): array
    {
        $scope=self::scope($query);
        if (!$selection) {
            $scope=$query->filters;
            if (!isset($scope['level'])) $scope['levels']='["error","critical","alert","emergency"]';
        } else foreach (['trace_id','request_id'] as $key) if(isset($selection[$key])) $scope[$key]=$selection[$key];
        [$where,$params]=LogRepository::predicate(LogQuery::parse($scope));
        if (isset($selection['event_id'])) { $where.=' AND id=CAST(:anchor AS uuid)'; $params['anchor']=$selection['event_id']; }
        return ['SELECT '.self::projection($messages).' FROM log_events WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT '.(self::SEEDS+1),$params];
    }
    public static function relatedStatement(LogQuery $query,array $seeds,bool $messages): ?array
    {
        [$where,$params]=LogRepository::predicate(LogQuery::parse(self::scope($query)));
        $conditions=[];
        foreach (['trace_id','request_id'] as $key) {
            $values=[];
            foreach (array_slice($seeds,0,self::SEEDS) as $row) {
                $value=$row[$key]??null;
                if(is_string($value)&&$value!==''&&strlen($value)<=128&&!in_array($value,$values,true)) $values[]=$value;
            }
            if (!$values) continue;
            $holders=[]; foreach($values as $i=>$value){$name=$key.'_correlation_'.$i;$holders[]=':'.$name;$params[$name]=$value;}
            $conditions[]=$key.' IN ('.implode(',',$holders).')';
        }
        if (!$conditions) return null;
        // Exclude seed IDs so that a recent error cannot consume the related-event budget twice.
        $exclude=[];foreach(array_slice($seeds,0,self::SEEDS) as $i=>$row){$name='seed_'.$i;$params[$name]=LogQuery::uuid($row['id']);$exclude[]='CAST(:'.$name.' AS uuid)';}
        $where.=' AND ('.implode(' OR ',$conditions).') AND id NOT IN ('.implode(',',$exclude).')';
        return ['SELECT '.self::projection($messages).' FROM log_events WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT '.(Assistant::MAX_ROWS+1),$params];
    }
    public static function sample(PDO $pdo,LogQuery $query,array $selection,int $budget,bool $messages): array
    {
        // Explicit selectors intentionally ignore keyword and severity; auto mode uses them only for seeds.
        $runtimeQuery=$selection?LogQuery::parse(self::scope($query)):$query;
        return (new SearchRuntime($pdo,$budget))->run($runtimeQuery,static function(LogRepository $repo,callable $limit)use($query,$selection,$messages):array{
            $read=static function(array $statement)use($repo,$limit):array{ $limit();$s=$repo->connection()->prepare($statement[0]);$s->execute($statement[1]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();return $rows; };
            $seeds=$read(self::seedStatement($query,$selection,$messages));
            $statement=self::relatedStatement($query,$seeds,$messages);
            return self::context($seeds,$statement?$read($statement):[],$messages);
        },true);
    }
    private static function text(mixed $value,int $max): ?string
    {
        if(!is_string($value)||$value===''||strlen($value)>$max||!preg_match('//u',$value))return null;
        $masked=Assistant::mask($value);
        return strlen($masked)<=$max?$masked:null;
    }
    // The context JSON is nested in a Responses API input string, so budget the escaped wire form.
    private static function wireBytes(array $event): int
    {
        $flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
        return strlen(json_encode(json_encode($event,$flags),$flags));
    }
    public static function context(array $seeds,array $related,bool $messages): array
    {
        $rows=[];$seedIds=[];
        foreach(array_slice($seeds,0,self::SEEDS) as $row){$id=LogQuery::uuid($row['id']);$rows[$id]=$row;$seedIds[$id]=true;}
        $more=count($seeds)>self::SEEDS;
        foreach($related as $row){$id=LogQuery::uuid($row['id']);if(isset($rows[$id]))continue;if(count($rows)>=Assistant::MAX_ROWS){$more=true;break;}$rows[$id]=$row;}
        uasort($rows,static fn($a,$b)=>strcmp($a['timestamp'],$b['timestamp'])?:strcmp($a['id'],$b['id']));
        $aliases=['trace_id'=>[],'request_id'=>[]];$events=[];$bytes=0;$textOmitted=0;$stackCount=0;$errorCount=0;
        foreach($rows as $id=>$row){
            $event=['id'=>$id,'timestamp'=>$row['timestamp'],'level'=>$row['level'],'seed'=>isset($seedIds[$id])];
            foreach(['trace_id'=>'trace','request_id'=>'request'] as $key=>$prefix){
                $raw=$row[$key]??null;
                if(is_string($raw)&&$raw!=='') { $mapKey='id:'.$raw; $aliases[$key][$mapKey]??=$prefix.'-'.(count($aliases[$key])+1);$event[$prefix]=$aliases[$key][$mapKey]; }
            }
            if(in_array($row['level'],['error','critical','alert','emergency'],true))++$errorCount;
            if($messages){
                foreach(['source'=>100,'service'=>255,'message'=>2048,'stack_trace'=>4096] as $key=>$max){
                    $value=self::text($row[$key]??null,$max);
                    if($value!==null)$event[$key]=$value;
                    elseif($key==='message'||($key==='stack_trace'&&(is_string($row[$key]??null)||in_array($row['stack_omitted']??false,[true,1,'1','t','true'],true)))) { $event[$key.'_omitted']=true; }
                }
                // Enforce escaped JSON byte budget, including worst-case control characters.
                $size=self::wireBytes($event);
                if($bytes+$size>self::MAX_BYTES-10000){foreach(['source','service','message','stack_trace'] as $key)unset($event[$key]);$event['text_omitted']=true;}
                if(isset($event['stack_trace']))++$stackCount;
                if(isset($event['message_omitted'])||isset($event['stack_trace_omitted'])||isset($event['text_omitted']))++$textOmitted;
            }
            $bytes+=self::wireBytes($event);$events[]=$event;
        }
        return ['events'=>$events,'truncated'=>$more,'messages_included'=>$messages,'investigation'=>['seed_count'=>count($seedIds),'related_count'=>count($events)-count($seedIds),'error_count'=>$errorCount,'stack_count'=>$stackCount,'text_omitted_count'=>$textOmitted,'correlation'=>'one-hop','ordering'=>'timestamp_asc']];
    }
}
