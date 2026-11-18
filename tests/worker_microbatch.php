<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{IngestBatch,IngestEvent,LogRepository};
use Cajeer\Logs\Queue\{PostgresQueueDriver,WorkerPolicy};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $label): void { global $n;if (!$ok) throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n"; }
function rejects(callable $fn): bool {try{$fn();}catch(Throwable){return true;}return false;}
$project='11111111-1111-4111-8111-111111111111';$token='22222222-2222-4222-8222-222222222222';$source='33333333-3333-4333-8333-333333333333';
$event=fn(string $message)=>new IngestEvent('app','info',$message,(object)[],'2026-01-01T00:00:00Z');
final class MicroPDO extends PDO {
    public bool $active=false; public array $jobs=[];public array $results=[];public array $calls=[];public int $inserts=0;public int $outbox=0;public int $commits=0;public int $rollbacks=0;public int $lastInserted=0;public int $attemptUpdates=0;
    public function __construct() {}
    public function inTransaction(): bool {return $this->active;}
    public function beginTransaction(): bool {$this->active=true;return true;}
    public function commit(): bool {$this->active=false;$this->commits++;return true;}
    public function rollBack(): bool {$this->active=false;$this->rollbacks++;return true;}
    public function exec(string $query): int|false {$this->calls[]=[$query,[]];return 0;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new MicroStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {$stmt=$this->prepare($query);$stmt->execute();return $stmt;}
}
final class MicroStatement extends PDOStatement {
    private array $params=[]; public function __construct(private MicroPDO $db,private string $query) {}
    public function execute(?array $params=null): bool {
        $this->params=$params??[]; $this->db->calls[]=[$this->query,$this->params];
        if (str_contains($this->query,'INSERT INTO log_events')) $this->db->inserts++;
        if (str_contains($this->query,'INSERT INTO webhook_deliveries')) $this->db->outbox++;
        if (str_contains($this->query,"UPDATE jobs SET status='completed'")) $this->db->results[$this->params['id']]=json_decode($this->params['result'],true,16,JSON_THROW_ON_ERROR);
        if (str_contains($this->query,"UPDATE job_attempts SET outcome='completed'")) $this->db->attemptUpdates+=intdiv(count($this->params),2);
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        if (str_contains($this->query,"SELECT id,project_id,token_id,attempts FROM jobs")) {
            $job=$this->db->jobs[(string)$this->params['id']]??null;
            return $job===null?false:['id'=>$this->params['id'],'project_id'=>$job['project'],'token_id'=>$job['token'],'attempts'=>1];
        }
        return false;
    }
    public function fetchColumn(int $column=0): mixed {
        if (str_contains($this->query,'lease_until>clock_timestamp()')) return true;
        if (str_contains($this->query,'CASE WHEN payload_bytes')) return $this->db->jobs[(string)$this->params['id']]['body'];
        if (str_contains($this->query,"current_setting('transaction_isolation')"))return 'read committed';
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if (str_contains($this->query,'INSERT INTO log_events')) {
            $rows=json_decode($this->params['events'],true,64,JSON_THROW_ON_ERROR);
            $ids=array_column($rows,'id');$this->db->lastInserted=count($ids);
            return array_reverse($ids); // PostgreSQL RETURNING order is not guaranteed
        }
        if (str_contains($this->query, "AS lease_live") && str_contains($this->query, 'FOR UPDATE')) {
            $rows=[];
            foreach($this->params as $key=>$id) {
                if (!str_starts_with($key,'id')) continue;
                $job=$this->db->jobs[(string)$id]??null;
                if ($job!==null) $rows[]=['id'=>(string)$id,'project_id'=>$job['project'],
                    'token_id'=>$job['token'],'attempts'=>1,
                    'lease_id'=>'99999999-9999-4999-8999-999999999999',
                    'lease_live'=>true,'body'=>$job['body']];
            }
            usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
            return $rows;
        }
        if (str_contains($this->query,'SELECT key,value FROM settings')) return [];
        return [];
    }
    public function rowCount(): int {return str_contains($this->query,"UPDATE job_attempts SET outcome='completed'")?intdiv(count($this->params),2):1;}
    public function closeCursor(): bool {return true;}
}
$db=new MicroPDO();
foreach (['1'=>[$event('first'),$event('second')],'2'=>[$event('third')]] as $id=>$events) $db->jobs[$id]=['project'=>$project,'token'=>$token,'body'=>IngestBatch::encodeJob($events,['app'=>$source])];
$claims=array_map(fn($id)=>['id'=>$id,'lease_id'=>'99999999-9999-4999-8999-999999999999','project_id'=>$project,'token_id'=>$token,'attempts'=>1],['1','2']);
$driver=new PostgresQueueDriver($db);
check($driver->processBatch($claims)===['completed','completed'],'real grouped processing returns one status per claim');
check($db->inserts===1&&$db->lastInserted===3&&$db->outbox===2,'one log INSERT and independent webhook deliveries');
check(count($db->results['1']['event_ids'])===2&&count($db->results['2']['event_ids'])===1&&$db->results['1']['stored']===2&&$db->results['2']['stored']===1,'per-job event receipts preserve input order and boundaries');
check(count(array_unique([...$db->results['1']['event_ids'],...$db->results['2']['event_ids']]))===3,'no cross-job duplicate IDs');
check($db->attemptUpdates===2&&$db->commits===1&&!$db->active,'both attempts completed in one transaction');
check(count(array_filter($db->calls,fn($call)=>str_contains($call[0], 'AS lease_live')))===1&&count(array_filter($db->calls,fn($call)=>str_contains($call[0], "UPDATE job_attempts SET outcome='completed'")))===1,'single lease/payload query and single atomic attempt update for group');
check(rejects(fn()=>$driver->claimBatch(17))&&rejects(fn()=>$driver->processBatch([])),'invalid claim/process bounds rejected');
check((new WorkerPolicy(['batch_jobs'=>4]))->get('batch_jobs')===4,'worker batch size policy');
check(rejects(fn()=>new WorkerPolicy(['batch_jobs'=>0]))&&rejects(fn()=>new WorkerPolicy(['batch_jobs'=>17])),'policy rejects unsafe batch sizes');
// Incompatible project and token claims are never sent to one grouped INSERT.
$db2=new MicroPDO();$db2->jobs=$db->jobs;$db2->jobs['2']['project']='aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
$other=new PostgresQueueDriver($db2);
check(rejects(fn()=>$other->processBatch([$claims[0],['id'=>'2','lease_id'=>$claims[1]['lease_id'],'attempts'=>1,'project_id'=>$db2->jobs['2']['project'],'token_id'=>$token]]))===false,'batch mismatch isolates jobs through ordinary retry handling');
check($db2->inserts===2&&$db2->outbox===2&&$db2->rollbacks===1,'cross-project batch rolled back then isolated as two independent INSERTs');
echo "Worker microbatch: $n checks passed\n";
