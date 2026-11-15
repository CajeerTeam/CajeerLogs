<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{RestoreFence,DatabaseException,TransactionManager};
use Cajeer\Logs\Operations\BackupManager;
require dirname(__DIR__).'/core/bootstrap.php';

final class FenceStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function fetchColumn(int $column=0): mixed { return $this->value; }
    public function closeCursor(): bool { return true; }
}
final class FencePDO extends PDO {
    public bool $pending=false, $active=false, $original=false;
    public string $database='restore_test';
    public array $calls=[];
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        $this->calls[]=$query;
        return new FenceStatement(str_contains($query,'pg_db_role_setting')?$this->pending:(str_contains($query,'current_database')?$this->database:1));
    }
    public function exec(string $statement): int|false {
        $this->calls[]=$statement;
        if (str_contains($statement,"SET cajeerlogs.restore_state TO 'pending'")) $this->pending=true;
        if (str_contains($statement,'RESET cajeerlogs.restore_state')) $this->pending=false;
        return 0;
    }
    public function beginTransaction(): bool { $this->original=$this->pending;return $this->active=true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->active=false;return true; }
    public function rollBack(): bool { $this->pending=$this->original;$this->active=false;return true; }
}
$n=0;
function check(bool $ok,string $message):void { global $n;if(!$ok)throw new RuntimeException('FAIL '.$message);++$n;echo "PASS $message\n"; }
function rejects(callable $f):bool { try{$f();}catch(DatabaseException){return true;}return false; }
$p=new FencePDO();
check(!RestoreFence::pending($p),'ordinary database not quarantined');
check(rejects(fn()=>RestoreFence::quarantine($p,'other_database'))&&!$p->pending,'different target rejected before DDL');
check(rejects(fn()=>RestoreFence::quarantine($p,'x";DROP DATABASE a;'))&&!$p->pending,'database identifier injection rejected');
$p->beginTransaction();
check(rejects(fn()=>RestoreFence::quarantine($p,'restore_test'))&&!$p->pending,'quarantine cannot be rolled back with an outer transaction');
$p->rollBack();
RestoreFence::quarantine($p,'restore_test');
check($p->pending&&rejects(fn()=>RestoreFence::assertAvailable($p)),'quarantine rejects runtime');
check(rejects(fn()=>RestoreFence::publish($p,'restore_test'))&&$p->pending,'publication outside transaction rejected');
try { (new TransactionManager($p))->run(function()use($p){RestoreFence::publish($p,'restore_test');throw new RuntimeException('simulated failed commit');}); } catch(RuntimeException){}
check($p->pending,'rolled-back publication retains quarantine');
(new TransactionManager($p))->run(fn()=>RestoreFence::publish($p,'restore_test'));
RestoreFence::assertAvailable($p);
check(!$p->pending,'successful publication permits runtime');
check(BackupManager::expectedPending('0.1.0')===['0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql'],'exact older release history');
check(BackupManager::expectedPending('0.1.9')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.0')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.1')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.2')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.3')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.4')===['0013_ingest_signing.sql']&&BackupManager::expectedPending('0.2.5')===[],'previous history requires signing migration');
check(rejects(fn()=>BackupManager::expectedPending('0.2.6')),'unknown backup release refused');
echo "Restore fence: $n checks passed\n";
