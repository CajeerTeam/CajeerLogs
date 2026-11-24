<?php
declare(strict_types=1);
namespace Cajeer\Logs\AI;

use Cajeer\Logs\Log\{LogQuery,LogRepository,SearchRuntime};
use PDO;

/** Deterministic incident grouping over a bounded, project-scoped recent error sample. */
final class Incidents
{
    public const SAMPLE=200;
    public const MAX_GROUPS=Investigation::SEEDS;

    public static function statement(LogQuery $query,bool $messages): array
    {
        $filters=$query->filters;
        // Incident mode is always an error investigation, regardless of chat severity.
        unset($filters['level'],$filters['levels']);
        $filters['levels']='["error","critical","alert","emergency"]';
        [$where,$params]=LogRepository::predicate(LogQuery::parse($filters));
        return ['SELECT '.Investigation::projection($messages).' FROM log_events WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT '.(self::SAMPLE+1),$params];
    }
    private static function fingerprint(array $row,bool $messages): array
    {
        // No message grouping unless sharing log text is enabled. Fingerprints are local only.
        $message=$row['message']??null;
        if ($messages&&is_string($message)&&trim($message)!=='') {
            $text=Assistant::mask($message);
            // Normalize dynamic UUIDs, addresses, and numeric values; preserve static error wording.
            $text=preg_replace(['~\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b~i','~\b0x[0-9a-f]+\b~i','~\b\d+(?:\.\d+)*\b~','~\s+~u'],['<uuid>','<hex>','<number>',' '],$text);
            if(is_string($text)&&trim($text)!=='') {
                $stack=$row['stack_trace']??'';
                $first=is_string($stack)?strtok($stack,"\r\n"):false;
                // First exception class (if present), not a potentially sensitive exception message.
                $class=is_string($first)&&preg_match('~^([A-Za-z_][A-Za-z0-9_.\\\\]{0,150})(?=[:\s]|$)~',$first,$m)?$m[1]:'';
                return ['pattern',hash('sha256',json_encode([$row['source']??null,$row['service']??null,$class,trim($text)],JSON_THROW_ON_ERROR))];
            }
        }
        foreach(['trace_id'=>'trace','request_id'=>'request'] as $key=>$kind) {
            if(is_string($row[$key]??null)&&$row[$key]!=='')return [$kind,hash('sha256',$row[$key])];
        }
        return ['event',LogQuery::uuid($row['id'])];
    }
    /** Group counts refer to the sampled errors, never total project counts. */
    public static function group(array $rows,bool $messages): array
    {
        $groups=[];$seen=[];
        foreach(array_slice($rows,0,self::SAMPLE) as $row){
            $id=LogQuery::uuid($row['id']);if(isset($seen[$id]))continue;$seen[$id]=true;
            [$basis,$hash]=self::fingerprint($row,$messages);$key=$basis.':'.$hash;
            if(!isset($groups[$key]))$groups[$key]=['basis'=>$basis,'sample_count'=>0,'first_seen'=>$row['timestamp'],'last_seen'=>$row['timestamp'],'representative_id'=>$id,'row'=>$row];
            $g=&$groups[$key];++$g['sample_count'];
            if(strcmp($row['timestamp'],$g['first_seen'])<0)$g['first_seen']=$row['timestamp'];
            if(strcmp($row['timestamp'],$g['last_seen'])>0){$g['last_seen']=$row['timestamp'];$g['representative_id']=$id;$g['row']=$row;}
            unset($g);
        }
        $groups=array_values($groups);
        usort($groups,static fn($a,$b)=>($b['sample_count']<=>$a['sample_count'])?:strcmp($b['last_seen'],$a['last_seen'])?:strcmp($a['representative_id'],$b['representative_id']));
        $seeds=[];$selected=[];
        foreach(array_slice($groups,0,self::MAX_GROUPS) as $i=>$group){$seeds[]=$group['row'];unset($group['row']);$selected[]=['incident'=>'incident-'.($i+1)]+$group;}
        return ['seeds'=>$seeds,'summary'=>['sampled_errors'=>count($seen),'group_count'=>count($groups),'sample_truncated'=>count($rows)>self::SAMPLE,'groups_truncated'=>count($groups)>self::MAX_GROUPS,'groups'=>$selected]];
    }
    public static function sample(PDO $pdo,LogQuery $query,int $budget,bool $messages): array
    {
        return (new SearchRuntime($pdo,$budget))->run($query,static function(LogRepository $repo,callable $limit)use($query,$messages):array{
            $read=static function(array $statement)use($repo,$limit):array{$limit();$s=$repo->connection()->prepare($statement[0]);$s->execute($statement[1]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();return $rows;};
            $grouped=self::group($read(self::statement($query,$messages)),$messages);
            $related=Investigation::relatedStatement($query,$grouped['seeds'],$messages);
            $context=Investigation::context($grouped['seeds'],$related?$read($related):[],$messages);
            $context['incidents']=$grouped['summary'];
            $context['truncated']=$context['truncated']||$context['incidents']['sample_truncated']||$context['incidents']['groups_truncated'];
            return $context;
        },true);
    }
}
