<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Project-scoped, metadata-only dead-letter operations. No payload or credentials leave this service. */
final class DeadLetterManager
{
    public const REASONS=['transaction_conflict','lock_timeout','statement_timeout','database_error','processing_error','invalid_payload','lease_expired','attempts_exhausted','discarded_by_operator'];
    public function __construct(private readonly PDO $pdo, private readonly RetryPolicy $policy=new RetryPolicy()) {}
    private static function invalid(): never { throw new HttpException(422,'invalid_payload','Invalid dead-letter filters or selection.'); }
    public static function id(mixed $id): string {
        if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||(strlen($id)===19&&strcmp($id,'9223372036854775807')>0)) self::invalid();
        return $id;
    }
    private static function integer(mixed $v,int $min,int $max): int {
        if (!is_int($v)&&(!is_string($v)||!preg_match('/^[0-9]+$/D',$v))) self::invalid();
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
        if ($n===false) self::invalid(); return $n;
    }
    private static function uuid(mixed $v): string {
        if (!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$v)) self::invalid(); return strtolower($v);
    }
    public static function filters(array $q): array {
        if (array_diff(array_keys($q),['limit','cursor','reason','source_id','min_attempts','min_age_seconds'])!==[]) self::invalid();
        $out=['limit'=>self::integer($q['limit']??50,1,100)];
        if (isset($q['cursor'])) $out['cursor']=self::id($q['cursor']);
        if (isset($q['reason'])) { if (!in_array($q['reason'],self::REASONS,true)) self::invalid(); $out['reason']=$q['reason']; }
        if (isset($q['source_id'])) $out['source_id']=self::uuid($q['source_id']);
        if (isset($q['min_attempts'])) $out['min_attempts']=self::integer($q['min_attempts'],1,100);
        if (isset($q['min_age_seconds'])) $out['min_age_seconds']=self::integer($q['min_age_seconds'],0,315360000);
        return $out;
    }
    /** IDs are strings (bigint exceeds JS safe integer range). Attempts are CAS preconditions. */
    public static function selection(mixed $items): array {
        if (!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>20) self::invalid();
        $out=[];$seen=[];
        foreach ($items as $item) {
            if (!is_array($item)||count($item)!==2||!isset($item['id'],$item['attempts'])||!is_int($item['attempts'])) self::invalid();
            $id=self::id($item['id']); if (isset($seen[$id])) self::invalid();$seen[$id]=true;
            $out[]=['id'=>$id,'attempts'=>self::integer($item['attempts'],0,100)];
        }
        usort($out,static fn($a,$b)=>strlen($a['id'])<=>strlen($b['id'])?:strcmp($a['id'],$b['id']));
        return $out;
    }
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql);$s->execute($params);return $s; }
    private const FIELDS='id,project_id,status,attempts,max_attempts,created_at,available_at,completed_at,last_error,payload_events,payload_bytes';
    private static function metadata(array $r): array {
        $r['id']=(string)$r['id']; foreach (['attempts','max_attempts','payload_events','payload_bytes'] as $k) $r[$k]=(int)$r[$k];
        $r['last_error']=$r['last_error']===null?null:(in_array($r['last_error'],self::REASONS,true)?$r['last_error']:'other');return $r;
    }
    public function page(string $project,array $query): array {
        $f=self::filters($query);$where="queue='ingest-v1' AND project_id=:project AND status='dead'";$p=['project'=>$project];
        foreach (['cursor'=>'id<CAST(:cursor AS bigint)','reason'=>'last_error=:reason','min_attempts'=>'attempts>=CAST(:min_attempts AS integer)','min_age_seconds'=>"completed_at<=statement_timestamp()-CAST(:min_age_seconds AS integer)*interval '1 second'",'source_id'=>"EXISTS (SELECT 1 FROM jsonb_each_text(CASE WHEN jsonb_typeof(payload->'sources')='object' THEN payload->'sources' ELSE '{}'::jsonb END) s WHERE s.value=:source_id)"] as $key=>$sql) {
            if (isset($f[$key])) {$where.=' AND '.$sql;$p[$key]=$f[$key];}
        }
        $rows=$this->sql('SELECT '.self::FIELDS.' FROM jobs WHERE '.$where.' ORDER BY id DESC LIMIT '.($f['limit']+1),$p)->fetchAll();
        $next=null;if(count($rows)>$f['limit']) {array_pop($rows);$next=(string)end($rows)['id'];}
        return ['items'=>array_map(self::metadata(...),$rows),'next_cursor'=>$next];
    }
    public function detail(string $project,string $id): array {
        $id=self::id($id);
        // One snapshot for metadata and history, even if another worker completes the retry.
        return (new TransactionManager($this->pdo))->run(function() use($project,$id){
            $row=$this->sql('SELECT '.self::FIELDS." FROM jobs WHERE queue='ingest-v1' AND project_id=:project AND id=:id",['project'=>$project,'id'=>$id])->fetch();
            if (!$row) throw new HttpException(404,'not_found','Job not found.');
            $history=$this->sql('SELECT attempt,started_at,finished_at,outcome,error FROM job_attempts WHERE job_id=:id ORDER BY attempt DESC LIMIT 100',['id'=>$id])->fetchAll();
            foreach($history as &$a) {$a['attempt']=(int)$a['attempt'];$a['error']=$a['error']===null?null:(in_array($a['error'],self::REASONS,true)?$a['error']:'other');}unset($a);
            return ['job'=>self::metadata($row),'attempts'=>$history];
        },isolation:'REPEATABLE READ',readOnly:true);
    }
    /** All selected jobs are locked in bigint order, checked before any change, and audited atomically. */
    public function change(string $project,string $action,array $items,?string $actor,?string $requestId=null): array {
        if (!in_array($action,['retry','discard'],true)) self::invalid();$items=self::selection($items);
        if (!$this->pdo->inTransaction()) throw new \LogicException('Dead-letter mutation requires a transaction.');
        $this->pdo->exec('LOCK TABLE jobs IN ROW EXCLUSIVE MODE');
        foreach($items as $item) {
            $row=$this->sql("SELECT status,attempts FROM jobs WHERE queue='ingest-v1' AND project_id=:project AND id=:id FOR UPDATE",['project'=>$project,'id'=>$item['id']])->fetch();
            if (!$row) throw new HttpException(404,'not_found','Job not found.');
            if ($row['status']!=='dead'||(int)$row['attempts']!==$item['attempts']||($action==='retry'&&(int)$row['attempts']>=100)) throw new HttpException(409,'conflict','Job changed or retry budget exhausted. Refresh the selection.');
        }
        $driver=new PostgresQueueDriver($this->pdo, retryPolicy:$this->policy);$audit=new AuditLogger($this->pdo);
        foreach($items as $item) {
            if (!$driver->$action($item['id'])) throw new HttpException(409,'conflict','Job changed.');
            $audit->record($action==='retry'?'queue.retried':'queue.discarded',['project_id'=>$project,'job_id'=>$item['id'],'attempts'=>$item['attempts'],'request_id'=>$requestId],$actor,$item['id'],null,$actor===null?'system':'user');
        }
        return ['changed'=>count($items),'items'=>array_map(fn($i)=>['id'=>$i['id'],'status'=>$action==='retry'?'pending':'cancelled'],$items)];
    }
    /** CLI uses the same transaction/audit path; no unaudited operator mutation. */
    public function cliChange(string $id,string $action): bool {
        return (new TransactionManager($this->pdo))->run(function()use($id,$action){
            $this->pdo->exec('LOCK TABLE jobs IN ROW EXCLUSIVE MODE');
            $row=$this->sql("SELECT project_id,attempts,status FROM jobs WHERE queue='ingest-v1' AND id=:id FOR UPDATE",['id'=>self::id($id)])->fetch();
            if (!$row||$row['status']!=='dead'||($action==='retry'&&(int)$row['attempts']>=100)) return false;
            $this->change($row['project_id'],$action,[['id'=>$id,'attempts'=>(int)$row['attempts']]],null);return true;
        },maxAttempts:3);
    }
}
