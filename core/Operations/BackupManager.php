<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Database\TransactionManager;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,MigrationRunner};
use Cajeer\Logs\Kernel\Application;
use PDO;
use Cajeer\Logs\Database\{IntegrityInspector,RestoreFence};

/** Private, atomic custom-format PostgreSQL backups. No shell or destructive in-place restore. */
final class BackupManager
{
    public function __construct(private readonly Application $app) {}
    public function root(bool $create=true): string
    {
        $path=$this->app->config()->get('operations.backup_dir');
        if ($path==='') { $path=$this->app->basePath('storage/backups/private'); }
        if (!is_string($path) || !str_starts_with($path,'/') || str_contains($path,"\0") || is_link($path)) { throw new DatabaseException('Backup directory must be an absolute private directory.'); }
        if (!is_dir($path) && (!$create || !mkdir($path,0700,true))) { throw new DatabaseException('Cannot create backup directory.'); }
        $real=realpath($path); $public=realpath($this->app->basePath('public'));
        if ($real===false || $real!==rtrim($path,'/') || $real==='/' || ($public!==false && ($real===$public || str_starts_with($real,$public.'/'))) || (fileperms($real)&0077)!==0 || !\Cajeer\Logs\Installer\SecureFile::directory($real)) { throw new DatabaseException('Backup directory must be private (0700), writable and outside public/.'); }
        return $real;
    }
    public function location(string $id): string
    {
        if (!preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$id)) { throw new DatabaseException('Invalid backup ID.'); }
        $path=$this->root(false).'/'.$id;
        if (!\Cajeer\Logs\Installer\SecureFile::directory($path) || (fileperms($path)&0077)!==0) { throw new DatabaseException('Backup directory missing or unsafe.'); }
        return $path;
    }
    private function env(string $database): array
    {
        $c=$this->app->config();
        // Never inherit PGOPTIONS, PGSERVICE, or other libpq overrides. Password stays out of argv.
        $env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','LANG'=>'C','LC_ALL'=>'C', 'PGHOST'=>$c->get('database.host'),'PGPORT'=>(string)$c->get('database.port'),'PGDATABASE'=>$database,'PGUSER'=>$c->get('database.username'),'PGPASSWORD'=>$c->get('database.password'),'PGSSLMODE'=>$c->get('database.sslmode'),'PGGSSENCMODE'=>'disable','PGAPPNAME'=>'cajeerlogs:backup','PGCONNECT_TIMEOUT'=>(string)$c->get('database.connect_timeout')];
        if ($c->get('database.sslrootcert')!=='') { $env['PGSSLROOTCERT']=$c->get('database.sslrootcert'); }
        return $env;
    }
    private function process(array $args,string $database): void
    {
        $tool=array_shift($args); $bin=$this->app->config()->get('operations.'.$tool);
        if (!is_string($bin)||!str_starts_with($bin,'/')||!is_file($bin)||!is_executable($bin)) { throw new DatabaseException('Missing PostgreSQL client executable: '.$tool); }
        $process=proc_open([$bin,...$args],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$this->env($database),['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new DatabaseException('Cannot start PostgreSQL client.'); }
        $deadline=hrtime(true)+(int)$this->app->config()->get('operations.backup_timeout_seconds')*1000000000;
        $exit=-1;
        try {
            do {
                $status=proc_get_status($process);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
                if (hrtime(true)>=$deadline) {
                    proc_terminate($process); usleep(200000);
                    if (proc_get_status($process)['running']) proc_terminate($process,9);
                    throw new DatabaseException($tool.' exceeded backup process timeout.');
                }
                usleep(50000);
            } while (true);
        } finally { $closed=proc_close($process); }
        if (($exit===-1?$closed:$exit)!==0) { throw new DatabaseException($tool.' failed; check client version, permissions, disk space and PostgreSQL logs.'); }
    }
    private function regular(string $path): void
    {
        if (!\Cajeer\Logs\Installer\SecureFile::privateFile($path)||!is_readable($path)) { throw new DatabaseException('Backup file missing or unsafe.'); }
    }
    public function create(): array
    {
        $root=$this->root(); $free=disk_free_space($root); if ($free===false || $free<(int)$this->app->config()->get('operations.backup_min_free_bytes')) throw new DatabaseException('Insufficient free space for backup.'); $id='backup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)); $temp=$root.'/.'.$id.'.partial';
        if (!mkdir($temp,0700)) { throw new DatabaseException('Cannot create temporary backup.'); }
        $guard=null;
        try {
            $pdo=$this->app->container()->get(PDO::class); $schema=$this->app->container()->get(ConnectionFactory::class)->schema(); $db=$this->app->config()->get('database.database');
            $guard=$this->app->container()->get(MigrationRunner::class)->guard();
            $archive=$temp.'/database.dump';
            // Pre-create with mode 0600; pg_dump writes only to our private directory.
            $f=fopen($archive,'xb'); if ($f===false) { throw new DatabaseException('Cannot create backup archive.'); } fclose($f); chmod($archive,0600);
            $snapshotStarted=gmdate(DATE_ATOM); // Conservative bound: snapshot cannot predate this instant.
            $this->process(['pg_dump','--no-password','--format=custom','--no-owner','--no-privileges','--strict-names','--lock-wait-timeout=10s','--schema='.$schema,'--file='.$archive],$db);
            $this->regular($archive); $handle=fopen($archive,'r+b'); if ($handle===false) { throw new DatabaseException('Cannot synchronize backup archive.'); } try { if (!fsync($handle)) { throw new DatabaseException('Cannot synchronize backup archive.'); } } finally { fclose($handle); }
            $size=filesize($archive); $hash=hash_file('sha256',$archive);
            if (!$size||$hash===false) { throw new DatabaseException('Backup archive is empty or unreadable.'); }
            $this->process(['pg_restore','--list',$archive],$db);
            $meta=['format'=>1,'id'=>$id,'version'=>$this->app->version(),'schema'=>$schema,'created_at'=>gmdate(DATE_ATOM),'snapshot_started_at'=>$snapshotStarted,'bytes'=>$size,'sha256'=>$hash];
            $file=$temp.'/manifest.json'; $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
            if (file_put_contents($file,$json)!==strlen($json)||!chmod($file,0600)) { throw new DatabaseException('Cannot write backup manifest.'); }
            $handle=fopen($file,'r+b'); if ($handle===false) { throw new DatabaseException('Cannot synchronize manifest.'); } try { if (!fsync($handle)) { throw new DatabaseException('Cannot synchronize manifest.'); } } finally { fclose($handle); }
            self::syncDirectory($temp);
            if (!rename($temp,$root.'/'.$id)) { throw new DatabaseException('Cannot publish backup.'); }
            self::syncDirectory($root);
            return $meta;
        } finally {
            $guard?->release();
            if (is_dir($temp)) { foreach (['database.dump','manifest.json'] as $file) { if (is_file($temp.'/'.$file)) { unlink($temp.'/'.$file); } } rmdir($temp); }
        }
    }
    public static function syncDirectory(string $path): void
    {
        $f=@fopen($path,'r'); if ($f===false) throw new DatabaseException('Cannot open backup directory for synchronization.');
        try { if (!fsync($f)) throw new DatabaseException('Cannot synchronize backup directory.'); } finally { fclose($f); }
    }
    public function verify(string $id): array { return $this->verifyDirectory($this->location($id),$id); }
    public function verifyDirectory(string $path,string $id): array
    { $manifest=$path.'/manifest.json'; $archive=$path.'/database.dump'; $this->regular($manifest); $this->regular($archive);
        if (filesize($manifest)>16384) { throw new DatabaseException('Invalid backup manifest.'); }
        $m=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR); clearstatcache(true,$archive);
        if (!is_array($m)||($m['format']??null)!==1||($m['id']??null)!==$id||!is_int($m['bytes']??null)||$m['bytes']!==filesize($archive)||!is_string($m['sha256']??null)||!hash_equals($m['sha256'],hash_file('sha256',$archive))||!is_string($m['schema']??null)||!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$m['schema'])) { throw new DatabaseException('Backup checksum or manifest mismatch.'); }
        if (isset($m['snapshot_started_at']) && (!is_string($m['snapshot_started_at']) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D',$m['snapshot_started_at']))) throw new DatabaseException('Invalid backup snapshot time.');
        $this->process(['pg_restore','--list',$archive],$this->app->config()->get('database.database'));
        return $m;
    }
    /** Explicit supported release histories, no acceptance of unknown versions. */
    public static function compatibleRelease(mixed $backup, string $current): bool
    {
        $versions=['0.0.9','0.1.0','0.1.1','0.1.2','0.1.3','0.1.4','0.1.5','0.1.6','0.1.7','0.1.8','0.1.9','0.2.0','0.2.1','0.2.2','0.2.3','0.2.4','0.2.5','0.2.6','0.2.7','0.2.8','0.2.9','0.3.0','0.3.1','0.3.2','0.3.3','0.3.4','0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1','0.4.2','0.4.3','0.4.4','0.4.5','0.4.6','0.4.7','0.4.8','0.4.9','0.4.10','0.5.0','0.5.1','0.5.2','0.5.3','0.5.4','0.5.5','0.5.6','0.5.7','0.5.8','0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.6.7','0.6.8','0.6.9','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4','0.7.5','0.7.6','0.7.7','0.7.8','0.7.9','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.8.8','0.8.9','0.9.0','0.9.1','0.9.2','0.9.3'];
        $from=array_search($backup,$versions,true); $to=array_search($current,$versions,true);
        return $from!==false && $to!==false && $from<=$to;
    }

    public static function expectedPending(string $version): array
    {
        if (!self::compatibleRelease($version,'0.9.3')) throw new DatabaseException('Unsupported backup release.');
        $migrations=['0009_project_management.sql','0010_source_management.sql','0011_token_management.sql','0012_database_integrity.sql','0013_ingest_signing.sql','0014_delivery_idempotency.sql','0015_authentication_sessions.sql','0016_saved_log_views.sql','0017_rbac_roles_permissions.sql','0018_identity_security.sql','0019_audit_accountability.sql','0020_rate_limit_buckets.sql','0021_retention_execution_history.sql','0022_queue_accounting.sql','0023_queue_backpressure.sql','0024_project_queue_fairness.sql','0025_queue_operations_metrics.sql','0026_webhook_subscriptions.sql','0027_export_jobs.sql','0028_analytics_replication.sql','0029_analytics_rollups.sql','0030_source_ingestion_monitoring.sql','0031_error_analytics.sql','0032_advanced_search.sql','0033_storage_tiers.sql'];
        return array_slice($migrations,match($version) {'0.5.1','0.5.2','0.5.3','0.5.4','0.5.5'=>14,'0.5.6'=>15,'0.5.7','0.5.8'=>16,'0.5.9','0.5.10','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6'=>17,'0.6.7'=>18,'0.6.8','0.6.9','0.7.0','0.7.1'=>19,'0.7.2'=>20,'0.7.3'=>21,'0.7.4','0.7.5'=>22,'0.7.6'=>23,'0.7.7'=>24,'0.7.8','0.7.9','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.8.8','0.8.9','0.9.0','0.9.1','0.9.2','0.9.3'=>25,'0.4.9','0.4.10','0.5.0'=>13,'0.4.5','0.4.6','0.4.7','0.4.8'=>12,'0.4.4'=>11,'0.4.3'=>10,'0.4.2'=>9,'0.3.5','0.3.6','0.3.7','0.3.8','0.3.9','0.4.0','0.4.1'=>8,'0.3.4','0.3.3','0.3.2'=>7,'0.3.1','0.3.0','0.2.9','0.2.8','0.2.7'=>6,'0.2.6','0.2.5'=>5,'0.2.4','0.2.3','0.2.2','0.2.1','0.2.0','0.1.9'=>4,'0.1.8'=>3,'0.1.7'=>2,'0.1.6'=>1,default=>0});
    }
    public function restore(string $id,string $target): array
    {
        return $this->restoreDirectory($this->location($id),$id,$target);
    }
    /** Restore an authenticated, checked isolated archive without ever opening a local backup. */
    public function restoreDirectory(string $directory,string $id,string $target): array
    {
        if (is_link($directory) || !\Cajeer\Logs\Installer\SecureFile::directory($directory) ||
            (fileperms($directory)&0077)!==0) throw new DatabaseException('Unsafe disaster recovery staging directory.');
        $m=$this->verifyDirectory($directory,$id); $c=$this->app->config();
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$target)||strcasecmp($target,$c->get('database.database'))===0||in_array($target,['postgres','template0','template1'],true)||$m['schema']!==$this->app->container()->get(ConnectionFactory::class)->schema()||!self::compatibleRelease($m['version']??null,$this->app->version())) { throw new DatabaseException('Restore requires a different empty database, matching schema and compatible release version.'); }
        $targetConfig=clone $c; $targetConfig->set('database.database',$target); $targetConfig->set('database.schema','public');
        $pdo=(new ConnectionFactory($targetConfig))->make('pgsql');
        // Only the owner of an exclusive freshly provisioned database may restore.
        if (!$pdo->query("SELECT current_user=pg_get_userbyid(datdba) FROM pg_database WHERE datname=current_database()")->fetchColumn()) { throw new DatabaseException('Restore target must be owned by the configured application role.'); }
        if (!$pdo->query("SELECT pg_try_advisory_lock(hashtextextended('cajeerlogs:restore',0))")->fetchColumn()) { throw new DatabaseException('Restore already running on target.'); }
        if ($pdo->query("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid())")->fetchColumn()) { throw new DatabaseException('Restore requires an exclusive target without other connections.'); }
        // Require a freshly provisioned target, with no user objects in any non-system schema.
        $check=$pdo->query("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT LIKE 'pg\\_%' AND n.nspname<>'information_schema') OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname NOT LIKE 'pg\\_%' AND n.nspname<>'information_schema') OR EXISTS(SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname NOT LIKE 'pg\\_%' AND n.nspname<>'information_schema') OR EXISTS(SELECT 1 FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname NOT IN ('information_schema','public')) OR EXISTS(SELECT 1 FROM pg_extension WHERE extname<>'plpgsql') OR EXISTS(SELECT 1 FROM pg_largeobject_metadata) OR EXISTS(SELECT 1 FROM pg_foreign_server) OR EXISTS(SELECT 1 FROM pg_publication)")->fetchColumn();
        if ($check) { throw new DatabaseException('Restore target is not empty.'); }
        // Persist quarantine before pg_restore opens its independent session. The marker
        // survives a killed process, failed migration or failed validation. Reuse this PDO:
        // ordinary ConnectionFactory sessions deliberately reject a quarantined database.
        RestoreFence::quarantine($pdo,$target);
        try {
            $this->process(['pg_restore','--no-password','--exit-on-error','--single-transaction','--clean','--if-exists','--no-owner','--no-privileges','--dbname='.$target,$directory.'/database.dump'],$target);
            $pdo->exec('SET search_path TO "'.$m['schema'].'", pg_catalog, pg_temp');
            $runner=new MigrationRunner($pdo,$this->app->basePath('migrations/pgsql'),$m['schema']);
            // A compatible snapshot must contain exactly the history of its declared release.
            if ($runner->status()['pending']!==self::expectedPending($m['version'])) {
                throw new DatabaseException('Restored migration history inconsistent with backup release.');
            }
            $runner->migrate();
            $runner->assertCurrent();
            // Structural checks are not enough: verify all logical relationships before
            // publishing the target. Never automatically repair or discard historic rows.
            $integrity=(new IntegrityInspector($pdo))->inspect(1);
            if ($integrity['status']!=='ok') throw new DatabaseException('Restored data failed logical integrity checks.');
            if (!(new \Cajeer\Logs\Audit\AuditLogger($pdo))->verify()['valid']) throw new DatabaseException('Restored audit chain failed integrity checks.');
            if (!(new \Cajeer\Logs\Queue\QueueAccounting($pdo))->verify()['valid']) throw new DatabaseException('Restored queue accounting failed integrity checks.');
            // Credentials and publication share a durable transaction: no restored secret
            // becomes usable if the process dies before the final commit.
            (new TransactionManager($pdo))->run(function () use ($pdo,$target,$c) {
                // Pending enrollment is bound to a session that will be revoked.
                $pdo->exec('DELETE FROM user_totp WHERE pending_session IS NOT NULL');
                \Cajeer\Logs\Security\TwoFactorManager::fromConfig($pdo,$c)->verifyKeys();
                $pdo->exec('DELETE FROM auth_sessions; DELETE FROM user_recovery_codes; UPDATE api_tokens SET revoked_at=now(), updated_at=now(), revision=revision+1 WHERE revoked_at IS NULL');
                RestoreFence::publish($pdo,$target);
            }, maxAttempts: 3);
        } catch (\Throwable $e) {
            throw new DatabaseException('Restore did not complete; target remains quarantined or commit outcome must be checked. Never switch production to this target. Recreate an empty target and retry.',0,$e);
        }
        return ['id'=>$id,'database'=>$target,'schema'=>$m['schema'],'status'=>'restored','integrity_verified'=>true,'sessions_revoked'=>true,'api_tokens_revoked'=>true,'recovery_codes_invalidated'=>true];
    }
    public function list(): array
    {
        $root=$this->root(); $ids=[];
        foreach (scandir($root)?:[] as $id) { if (preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$id)&&is_dir($root.'/'.$id)&&!is_link($root.'/'.$id)) { $ids[]=$id; } }
        rsort($ids,SORT_STRING); return $ids;
    }
}
