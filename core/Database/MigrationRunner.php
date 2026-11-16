<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

final class MigrationRunner
{
    private readonly SchemaInspector $inspector;
    public function __construct(private readonly PDO $pdo, private readonly string $directory, private readonly string $schema, private readonly int $statementTimeoutMs=600000, private readonly int $maxBackfillBatches=1000)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $schema) || str_starts_with($schema,'pg_') || $schema==='information_schema') throw new DatabaseException('Invalid migration schema.');
        if ($statementTimeoutMs<1000 || $statementTimeoutMs>86400000 || $maxBackfillBatches<1 || $maxBackfillBatches>100000) throw new DatabaseException('Invalid migration execution limits.');
        $this->inspector=new SchemaInspector($pdo);
    }

    private function files(): array
    {
        $paths=glob($this->directory.'/*.sql');
        if (!$paths) throw new DatabaseException('No PostgreSQL migrations found.');
        sort($paths,SORT_STRING); $files=[]; $expected=1;
        foreach ($paths as $path) {
            $name=basename($path);
            if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/D',$name,$m) || (int)$m[1]!==$expected++) throw new DatabaseException('Migration filenames must be sequential and unique.');
            $sql=file_get_contents($path);
            if ($sql===false || trim($sql)==='') throw new DatabaseException('Unreadable or empty migration: '.$name);
            $files[$name]=['sql'=>$sql,'checksum'=>hash('sha256',$sql)]+MigrationSql::parse($sql);
        }
        return $files;
    }
    private function table(string $name='schema_migrations',?string $schema=null): string { return '"'.($schema??$this->schema).'"."'.$name.'"'; }
    private function key(): string { return 'cajeerlogs:migrations:'.$this->schema; }
    private function exists(string $name): bool {
        $q=$this->pdo->prepare('SELECT to_regclass(:name) IS NOT NULL'); $q->execute(['name'=>$this->table($name)]); $exists=$q->fetchColumn()===true; $q->closeCursor(); return $exists;
    }
    private function history(): array {
        if (!$this->exists('schema_migrations')) return [];
        return $this->pdo->query('SELECT version,checksum FROM '.$this->table().' ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    private function compare(array $files,array $history): array {
        $pending=[]; $seenPending=false;
        foreach ($files as $name=>$file) {
            if (!array_key_exists($name,$history)) { $pending[]=$name; $seenPending=true; continue; }
            if ($seenPending || !hash_equals($file['checksum'],(string)$history[$name])) throw new DatabaseException('Migration history mismatch: '.$name);
            unset($history[$name]);
        }
        if ($history!==[]) throw new DatabaseException('Database contains unknown migrations; refusing downgrade.');
        return $pending;
    }
    private static function historyHash(array $history): string { ksort($history,SORT_STRING); return hash('sha256',json_encode($history,JSON_THROW_ON_ERROR)); }
    private function state(): ?array {
        if (!$this->exists('schema_migration_state')) return null;
        $row=$this->pdo->query('SELECT history_hash,snapshot FROM '.$this->table('schema_migration_state').' WHERE singleton=true')->fetch();
        if (!$row) return null;
        $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new DatabaseException('Invalid migration schema snapshot.');
        return ['history_hash'=>$row['history_hash'],'snapshot'=>$snapshot];
    }
    private function integrity(array $history): array {
        $state=$this->state();
        if ($state===null) {
            if ($history!==[]) return ['integrity'=>'unverified','drift'=>[]];
            $issues=SchemaInspector::differences([], $this->inspector->snapshot($this->schema));
            return ['integrity'=>$issues===[]?'uninitialized':'drift','drift'=>$issues];
        }
        if (!hash_equals($state['history_hash'],self::historyHash($history))) return ['integrity'=>'drift','drift'=>[['object'=>'migration_history','change'=>'changed']]];
        $issues=SchemaInspector::differences($state['snapshot'],$this->inspector->snapshot($this->schema));
        return ['integrity'=>$issues===[]?'verified':'drift','drift'=>$issues];
    }
    /** A partially committed operation cannot disappear from a newer release's pending set. */
    private function validateJournal(array $files,array $history): void {
        if (!$this->exists('schema_migration_runs')) return;
        $rows=$this->pdo->query('SELECT version,checksum,mode,status FROM '.$this->table('schema_migration_runs').' ORDER BY version')->fetchAll();
        foreach ($rows as $row) {
            $name=$row['version']; $file=$files[$name]??null;
            if (isset($history[$name])) {
                if ($row['status']!=='applied' || !hash_equals($history[$name],$row['checksum']) || !$file || $row['mode']!==$file['mode']) throw new DatabaseException('Applied migration journal mismatch: '.$name);
                continue;
            }
            if ($row['status']==='applied') throw new DatabaseException('Migration journal claims an unapplied migration: '.$name);
            if ($file && (!hash_equals($file['checksum'],$row['checksum']) || $file['mode']!==$row['mode'])) throw new DatabaseException('Pending migration journal mismatch: '.$name);
            if (!$file && $row['mode']!=='transactional') throw new DatabaseException('Unfinished nontransactional migration is missing from this release: '.$name);
        }
    }
    /** Read-only consistent catalog/history snapshot, refuses to observe a partially applied migration. */
    public function status(bool $withJournal=true): array {
        if ($this->pdo->inTransaction()) throw new DatabaseException('Migration status requires its own transaction.');
        return (new TransactionManager($this->pdo))->run(function () use ($withJournal) {
            $lock=$this->pdo->prepare('SELECT pg_try_advisory_xact_lock_shared(hashtextextended(:key,0))'); $lock->execute(['key'=>$this->key()]); $held=$lock->fetchColumn()===true; $lock->closeCursor();
            if (!$held) throw new DatabaseException('Migration or backup currently holds the schema lock.');
            $files=$this->files(); $history=$this->history(); $pending=$this->compare($files,$history);
            $result=['applied'=>array_keys($history),'pending'=>$pending]+$this->integrity($history);
            $journalDrift=false; foreach ($result['drift'] as $issue) { if (str_contains($issue['object'],'schema_migration_runs') || str_contains($issue['object'],'schema_migration_attempts')) { $journalDrift=true; break; } }
            if (!$journalDrift) $this->validateJournal($files,$history);
            if ($withJournal && !$journalDrift && $this->exists('schema_migration_runs')) {
                $result['runs']=$this->pdo->query('SELECT version,mode,status,attempts,started_at,finished_at,duration_ms,last_error,batches FROM '.$this->table('schema_migration_runs').' ORDER BY version')->fetchAll();
            } else { $result['runs']=[]; }
            $result['attempts']=$withJournal && !$journalDrift && $this->exists('schema_migration_attempts')?$this->pdo->query('SELECT version,attempt,status,started_at,finished_at,duration_ms,error_category FROM '.$this->table('schema_migration_attempts').' ORDER BY version,attempt')->fetchAll():[];
            return $result;
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }
    public function assertCurrent(): void {
        $s=$this->status(false);
        if ($s['pending']!==[] || $s['integrity']!=='verified') throw new DatabaseException('Database schema is not current or verified; run migrate:plan and migrate:verify.');
    }
    public function guard(bool $integrityPreflight=false): MigrationGuard {
        $guard=new MigrationGuard($this->pdo,$this->key());
        try {
            RestoreFence::assertAvailable($this->pdo);
            if ($integrityPreflight) {
                $status=$this->status(false);
                if ($status['integrity']!=='verified' || !in_array($status['pending'],[[],['0021_retention_execution_history.sql'],['0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql'],['0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql']],true)) throw new DatabaseException('Integrity preflight requires the verified 0.1.8 or current schema.');
            } else $this->assertCurrent();
            return $guard;
        } catch (\Throwable $e) { $guard->release(); throw $e; }
    }
    public function plan(): array {
        $status=$this->status(); $files=$this->files(); $plan=[];
        foreach ($status['pending'] as $name) {
            $f=$files[$name]; $plan[]=['version'=>$name,'checksum'=>$f['checksum'],'mode'=>$f['mode'],'statements'=>count($f['statements']),'bytes'=>strlen($f['sql']),'operations'=>array_map(static fn($s)=>implode(' ',array_slice($s['words'],0,2)),$f['statements']),'requires_maintenance'=>$f['mode']==='transactional'];
        }
        return $status+['plan'=>$plan,'statement_timeout_ms'=>$this->statementTimeoutMs,'max_backfill_batches'=>$this->maxBackfillBatches];
    }
    private function rollback(): void { try { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); } catch (\Throwable) {} }
    private function transaction(callable $callback): mixed {
        return (new TransactionManager($this->pdo))->run(function () use ($callback) {
            $this->pdo->exec('SET LOCAL statement_timeout = '.$this->statementTimeoutMs);
            return $callback();
        });
    }
    private function createTables(?string $schema=null): void {
        $h=$this->table('schema_migrations',$schema); $s=$this->table('schema_migration_state',$schema); $r=$this->table('schema_migration_runs',$schema); $a=$this->table('schema_migration_attempts',$schema);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS $h (version text PRIMARY KEY, checksum text NOT NULL CHECK (length(checksum) = 64), applied_at timestamptz NOT NULL DEFAULT now());
            CREATE TABLE IF NOT EXISTS $s (singleton boolean PRIMARY KEY CHECK (singleton), history_hash text NOT NULL CHECK (length(history_hash)=64), snapshot jsonb NOT NULL, verified_at timestamptz NOT NULL DEFAULT now());
            CREATE TABLE IF NOT EXISTS $r (version text PRIMARY KEY, checksum text NOT NULL CHECK (length(checksum)=64), mode text NOT NULL CHECK (mode IN ('transactional','concurrent-index','backfill')), status text NOT NULL CHECK (status IN ('running','failed','applied')), attempts integer NOT NULL DEFAULT 1 CHECK (attempts>0), started_at timestamptz NOT NULL DEFAULT clock_timestamp(), finished_at timestamptz, duration_ms bigint, last_error text, batches bigint NOT NULL DEFAULT 0 CHECK (batches>=0));
            CREATE TABLE IF NOT EXISTS $a (version text NOT NULL REFERENCES $r(version) ON DELETE CASCADE, attempt integer NOT NULL CHECK (attempt>0), status text NOT NULL CHECK (status IN ('running','failed','applied','interrupted')), started_at timestamptz NOT NULL DEFAULT clock_timestamp(), finished_at timestamptz, duration_ms bigint, error_category text, PRIMARY KEY(version,attempt))");
    }
    private function saveState(): void {
        $snapshot=$this->inspector->snapshot($this->schema);
        $q=$this->pdo->prepare('INSERT INTO '.$this->table('schema_migration_state').' (singleton,history_hash,snapshot) VALUES (true,:hash,CAST(:snapshot AS jsonb)) ON CONFLICT(singleton) DO UPDATE SET history_hash=EXCLUDED.history_hash,snapshot=EXCLUDED.snapshot,verified_at=now()');
        $q->execute(['hash'=>self::historyHash($this->history()),'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    }
    /** Compare pre-0.1.2 schema with a trusted replay in a rollback-only shadow schema.
     * No user data is copied. A migration role needs CREATE on the database for this one-time adoption. */
    private function adopt(array $files,array $history): void {
        $actual=$this->inspector->snapshot($this->schema);
        $shadow='cl_migration_ref_'.bin2hex(random_bytes(8));
        $this->pdo->exec('SAVEPOINT migration_reference');
        try {
            $this->pdo->exec('CREATE SCHEMA "'.$shadow.'"');
            $this->pdo->exec('SET LOCAL search_path TO "'.$shadow.'", pg_catalog, pg_temp');
            $this->createTables($shadow);
            foreach ($history as $name=>$checksum) {
                if ($files[$name]['mode']!=='transactional') throw new DatabaseException('Cannot adopt an unverified history containing nontransactional migrations. Restore a verified backup.');
                foreach ($files[$name]['statements'] as $stmt) $this->pdo->exec($stmt['sql']);
            }
            $expected=$this->inspector->snapshot($shadow);
            $issues=SchemaInspector::differences($expected,$actual);
        } finally {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT migration_reference');
            $this->pdo->exec('RELEASE SAVEPOINT migration_reference');
        }
        if ($issues!==[]) throw new DatabaseException('Legacy schema drift: '.implode(', ',array_map(static fn($x)=>$x['change'].' '.$x['object'],array_slice($issues,0,12))).'. Refusing to adopt.');
        $this->saveState();
    }
    private function journal(string $name): ?array {
        $q=$this->pdo->prepare('SELECT * FROM '.$this->table('schema_migration_runs').' WHERE version=:version'); $q->execute(['version'=>$name]); return $q->fetch()?:null;
    }
    private function start(string $name,array $file): void {
        $old=$this->journal($name);
        if ($old && ($old['checksum']!==$file['checksum'] || $old['mode']!==$file['mode'] || $old['status']==='applied')) throw new DatabaseException('Migration attempt history mismatch: '.$name);
        $previous=$this->pdo->prepare('UPDATE '.$this->table('schema_migration_attempts')." SET status='interrupted',finished_at=clock_timestamp(),duration_ms=GREATEST(0,(extract(epoch FROM (clock_timestamp()-started_at))*1000)::bigint),error_category='interrupted' WHERE version=:version AND status='running'");
        $previous->execute(['version'=>$name]);
        $q=$this->pdo->prepare('INSERT INTO '.$this->table('schema_migration_runs').' AS runs (version,checksum,mode,status) VALUES (:version,:checksum,:mode,\'running\') ON CONFLICT(version) DO UPDATE SET status=\'running\',attempts=runs.attempts+1,started_at=clock_timestamp(),finished_at=NULL,duration_ms=NULL,last_error=NULL');
        $q->execute(['version'=>$name,'checksum'=>$file['checksum'],'mode'=>$file['mode']]);
        $a=$this->pdo->prepare('INSERT INTO '.$this->table('schema_migration_attempts').' (version,attempt,status) SELECT version,attempts,\'running\' FROM '.$this->table('schema_migration_runs').' WHERE version=:version');
        $a->execute(['version'=>$name]);
    }
    private function finish(string $name,string $status,?string $error=null): void {
        $q=$this->pdo->prepare('UPDATE '.$this->table('schema_migration_runs').' SET status=:status,finished_at=clock_timestamp(),duration_ms=GREATEST(0, (extract(epoch FROM (clock_timestamp()-started_at))*1000)::bigint),last_error=:error WHERE version=:version');
        $q->execute(['status'=>$status,'error'=>$error,'version'=>$name]);
        $a=$this->pdo->prepare('UPDATE '.$this->table('schema_migration_attempts').' SET status=:status,finished_at=clock_timestamp(),duration_ms=GREATEST(0,(extract(epoch FROM (clock_timestamp()-started_at))*1000)::bigint),error_category=:error WHERE version=:version AND status=\'running\'');
        $a->execute(['status'=>$status,'error'=>$error,'version'=>$name]);
    }
    private function record(string $name,array $file): void {
        $q=$this->pdo->prepare('INSERT INTO '.$this->table().' (version,checksum) VALUES (:version,:checksum)'); $q->execute(['version'=>$name,'checksum'=>$file['checksum']]);
        $this->finish($name,'applied'); $this->saveState();
    }
    private function indexInfo(string $name): ?array {
        $q=$this->pdo->prepare('SELECT c.relkind, r.relname AS table_name, i.indisunique AS is_unique, i.indisprimary AS is_primary, i.indisvalid AS valid, i.indisready AS ready, EXISTS(SELECT 1 FROM pg_catalog.pg_constraint x WHERE x.conindid=c.oid) AS constrained FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_catalog.pg_index i ON i.indexrelid=c.oid LEFT JOIN pg_catalog.pg_class r ON r.oid=i.indrelid WHERE n.nspname=:schema AND c.relname=:name');
        $q->execute(['schema'=>$this->schema,'name'=>$name]); return $q->fetch()?:null;
    }
    private function assertBaseline(?array $allowedIndex=null): void {
        $state=$this->state(); $history=$this->history();
        if (!$state || !hash_equals($state['history_hash'],self::historyHash($history))) throw new DatabaseException('Migration schema baseline is missing or incompatible.');
        $actual=$this->inspector->snapshot($this->schema);
        if ($allowedIndex!==null) {
            $key='index:'.$allowedIndex['name'];
            if (isset($state['snapshot'][$key])) throw new DatabaseException('Concurrent index already belongs to the applied schema.');
            unset($actual[$key]);
        }
        $issues=SchemaInspector::differences($state['snapshot'],$actual);
        if ($issues!==[]) throw new DatabaseException('Schema drift: '.implode(', ',array_map(static fn($x)=>$x['change'].' '.$x['object'],array_slice($issues,0,12))).'. Run migrate:verify.');
    }
    private function runConcurrent(string $name,array $file,?array $old): void {
        $idx=$file['index']; $info=$this->indexInfo($idx['name']);
        if ($info!==null) {
            // A journaled interrupted operation owns only this previously absent index name.
            // Rebuild rather than guessing whether a valid-looking index has the intended definition.
            if (!$old || $old['checksum']!==$file['checksum'] || $old['mode']!=='concurrent-index' || $old['status']==='applied' || $info['relkind']!=='i' || $info['table_name']!==$idx['table'] || $info['is_unique'] || $info['is_primary'] || $info['constrained']) throw new DatabaseException('Concurrent index name already exists or is not safe to rebuild.');
            $this->transaction(fn()=> $this->assertBaseline($idx));
            $this->pdo->exec('DROP INDEX CONCURRENTLY '.$this->table($idx['name']));
        }
        $this->transaction(fn()=> $this->assertBaseline());
        $this->pdo->exec($idx['sql']);
        $info=$this->indexInfo($idx['name']);
        if (!$info || !$info['valid'] || !$info['ready'] || $info['table_name']!==$idx['table']) throw new DatabaseException('Concurrent index is not valid; rerun migrate to rebuild.');
        $this->transaction(function() use($name,$file,$idx) { $this->assertBaseline($idx); $this->record($name,$file); });
    }
    private function runBackfill(string $name,array $file): void {
        for ($i=0;$i<$this->maxBackfillBatches;$i++) {
            $done=$this->transaction(function() use($name,$file) {
                $this->assertBaseline();
                $q=$this->pdo->query($file['statements'][0]['sql']); $count=$q->rowCount(); $q->closeCursor();
                if ($count===0) { $this->assertBaseline(); $this->record($name,$file); return true; }
                if ($count>10000) throw new DatabaseException('Backfill batch exceeds 10000 affected rows.');
                $q=$this->pdo->prepare('UPDATE '.$this->table('schema_migration_runs').' SET batches=batches+1 WHERE version=:version'); $q->execute(['version'=>$name]); return false;
            });
            if ($done) return;
        }
        throw new DatabaseException('Backfill paused at batch limit; rerun migrate to continue.');
    }
    /** Session-level lock spans per-file commits, concurrent index creation and backup exclusion. */
    public function migrate(): array {
        if ($this->pdo->inTransaction()) throw new DatabaseException('Migration runner requires its own transaction.');
        $files=$this->files(); $locked=false; $current='migration lock/history'; $applied=[]; $oldTimeout=null; $oldPath=null; $oldStrings=null; $oldCommit=null;
        try {
            $lock=$this->pdo->prepare('SELECT pg_advisory_lock(hashtextextended(:key,0))'); $lock->execute(['key'=>$this->key()]); $lock->closeCursor(); $locked=true;
            if ($this->pdo->query('SHOW transaction_read_only')->fetchColumn()!=='off' || $this->pdo->query('SELECT pg_is_in_recovery()')->fetchColumn()===true || $this->pdo->query('SHOW fsync')->fetchColumn()!=='on') throw new DatabaseException('Migrations require a writable primary with fsync enabled.');
            $oldCommit=$this->pdo->query('SHOW synchronous_commit')->fetchColumn();
            $oldTimeout=$this->pdo->query('SHOW statement_timeout')->fetchColumn(); $oldPath=$this->pdo->query('SHOW search_path')->fetchColumn(); $oldStrings=$this->pdo->query('SHOW standard_conforming_strings')->fetchColumn();
            $this->pdo->exec('SET statement_timeout = '.$this->statementTimeoutMs.'; SET synchronous_commit=on; SET standard_conforming_strings=on; SET search_path TO "'.$this->schema.'", pg_catalog, pg_temp');
            $pending=$this->transaction(function() use($files) {
                $history=$this->history(); $pending=$this->compare($files,$history); $state=$this->state();
                if (!$state) {
                    if ($this->exists('schema_migration_state') || $this->exists('schema_migration_runs') || $this->exists('schema_migration_attempts')) throw new DatabaseException('Migration baseline missing from existing engine metadata; refusing to re-adopt.');
                    if ($history===[]) {
                        $snapshot=$this->inspector->snapshot($this->schema);
                        if ($snapshot!==[]) throw new DatabaseException('Untracked objects in migration schema; refusing to initialize.');
                        $this->createTables(); $this->saveState();
                    } else { $this->createTables(); $this->adopt($files,$history); }
                } else {
                    $first=$pending[0]??null; $recover=null;
                    if ($first && $files[$first]['mode']==='concurrent-index') { $j=$this->journal($first); if ($j && $j['checksum']===$files[$first]['checksum'] && $j['mode']==='concurrent-index' && $j['status']!=='applied') $recover=$files[$first]['index']; }
                    $this->assertBaseline($recover);
                }
                $this->validateJournal($files,$history);
                return $pending;
            });
            foreach ($pending as $current) {
                $file=$files[$current]; $old=$this->journal($current);
                // A pre-existing name must not get a journal entry that would authorize its deletion on retry.
                if ($file['mode']==='concurrent-index' && !$old && $this->indexInfo($file['index']['name'])!==null) throw new DatabaseException('Concurrent index name already exists.');
                $this->transaction(fn()=> $this->start($current,$file));
                try {
                    if ($file['mode']==='concurrent-index') $this->runConcurrent($current,$file,$old);
                    elseif ($file['mode']==='backfill') $this->runBackfill($current,$file);
                    else $this->transaction(function() use($current,$file) { $this->assertBaseline(); foreach ($file['statements'] as $stmt) $this->pdo->exec($stmt['sql']); $this->record($current,$file); });
                    $applied[]=$current;
                } catch (\Throwable $e) {
                    // Failure journal is independent of the rolled-back migration. Never mask a lost COMMIT.
                    try { if (TransactionManager::usable($this->pdo) && !$this->pdo->inTransaction() && !isset($this->history()[$current])) $this->transaction(fn()=> $this->finish($current,'failed',DatabaseFailure::category($e))); } catch (\Throwable) {}
                    if ($e instanceof DatabaseException) throw $e;
                    throw new DatabaseException('Migration failed at '.$current.'; earlier applied migrations remain committed. Inspect migrate:plan and retry.',0,$e);
                }
            }
            return $applied;
        } catch (\Throwable $e) {
            $this->rollback();
            if ($e instanceof DatabaseException) throw $e;
            throw new DatabaseException('Migration failed at '.$current.'; check connection, permissions and schema lock.',0,$e);
        } finally {
            if ($locked) {
                try { $q=$this->pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:key,0))'); $q->execute(['key'=>$this->key()]); $q->closeCursor(); } catch (\Throwable) {}
                try { $q=$this->pdo->prepare("SELECT set_config('statement_timeout',:timeout,false),set_config('search_path',:path,false),set_config('standard_conforming_strings',:strings,false),set_config('synchronous_commit',:commit,false)"); $q->execute(['timeout'=>$oldTimeout,'path'=>$oldPath,'strings'=>$oldStrings,'commit'=>$oldCommit]); $q->closeCursor(); } catch (\Throwable) {}
            }
        }
    }
}
