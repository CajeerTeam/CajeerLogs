<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{IntegrityInspector,PerformanceInspector,DatabaseException,MigrationSql};
use Cajeer\Logs\Lifecycle\Retention;
use Cajeer\Logs\Log\{LogQuery,LogRepository};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function failure(callable $f):Throwable{try{$f();}catch(Throwable $e){return $e;}throw new RuntimeException('Expected failure');}
final class AuditStatement extends PDOStatement {
    public function __construct(private array $rows=[],private mixed $value=false){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
    public function closeCursor():bool{return true;}
}
final class AuditPDO extends PDO {
    public bool $active=false;public array $calls=[];public array $issues=[];public bool $fail=false;
    public function __construct(){}
    public function beginTransaction():bool{$this->active=true;$this->calls[]='BEGIN';return true;}
    public function inTransaction():bool{return $this->active;}
    public function commit():bool{$this->active=false;$this->calls[]='COMMIT';return true;}
    public function rollBack():bool{$this->active=false;$this->calls[]='ROLLBACK';return true;}
    public function exec(string $sql):int|false{$this->calls[]=$sql;return 0;}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{
        $this->calls[]=$query;if($this->fail)throw new PDOException('controlled error');
        if(str_starts_with($query,'SELECT count(*) FROM log_events WHERE source_id IS NULL'))return new AuditStatement([],3);
        foreach(IntegrityInspector::checks() as $name=>$sql)if(str_starts_with($query,$sql.' LIMIT '))return new AuditStatement($this->issues[$name]??[]);
        return new AuditStatement();
    }
}
$pdo=new AuditPDO();$audit=new IntegrityInspector($pdo);
check(failure(fn()=>$audit->inspect(0)) instanceof DatabaseException&&$pdo->calls===[],'invalid sample limit rejected before database');
check(failure(fn()=>$audit->inspect(101)) instanceof DatabaseException,'large sample limit rejected');
$report=$audit->inspect(2);check($report['status']==='ok'&&$report['legacy_unassigned']==='3'&&$report['read_only'],'legacy data reported separately');
check(in_array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY',$pdo->calls,true)&&end($pdo->calls)==='COMMIT','consistent read-only snapshot');
check(count($report['checks'])===count(IntegrityInspector::checks())&&count(IntegrityInspector::checks())>=15,'all logical checks executed');
$pdo->issues['job_token_scope']=['1','2','3'];$report=$audit->inspect(2);check($report['status']==='issues'&&$report['checks']['job_token_scope']===['sample_ids'=>['1','2'],'truncated'=>true,'issues_at_least'=>3],'bounded sample and lower-bound issue count');
$pdo->issues=['receipt_shape'=>['token-id']];$report=$audit->inspect(2);check($report['checks']['receipt_shape']['truncated']===false&&$report['checks']['receipt_shape']['issues_at_least']===1,'exact small result');
$pdo->fail=true;check(failure(fn()=>$audit->inspect()) instanceof PDOException&&!$pdo->active&&end($pdo->calls)==='ROLLBACK','SQL failure cannot yield successful audit');
$p='11111111-1111-4111-8111-111111111111';$trace="trace' OR 1=1";
$plans=PerformanceInspector::queries($p,$trace);check($plans['latest']===LogRepository::pageStatement(LogQuery::parse(['project_id'=>$p])),'EXPLAIN uses actual runtime page statement');
check($plans['trace'][1]['trace_id']===$trace&&!str_contains($plans['trace'][0],$trace),'trace bound, never interpolated');
check(!isset(PerformanceInspector::queries($p)['trace']),'trace plan optional');
check(failure(fn()=>PerformanceInspector::queries('invalid')) instanceof Throwable,'invalid project UUID rejected');
$sql=Retention::pruneSql();check(substr_count($sql,':limit')===1&&str_contains($sql,'UNION SELECT')&&str_contains($sql,'FOR UPDATE OF l SKIP LOCKED'),'retention has shared budget, deduplication and row fencing');
check(str_contains($sql,'l.project_id=p.id')&&str_contains($sql,'l.source_id=s.id')&&substr_count($sql,'LIMIT (SELECT n FROM budget)')===3,'indexable bounded per-policy candidates');
$m=MigrationSql::parse(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0012_database_integrity.sql'));check($m['mode']==='transactional'&&count($m['statements'])>=8,'integrity migration parses as atomic DDL');
echo "Database integrity: $n checks passed\n";
