<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Database\TransactionManager;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,MigrationRunner};
use Cajeer\Logs\Kernel\Application;
use PDO;

/** Private, atomic custom-format PostgreSQL backups. No shell or destructive in-place restore. */
final class BackupManager
{
    public function __construct(private readonly Application $app) {}
    private function root(): string
    {
        $path=$this->app->config()->get('operations.backup_dir');
        if ($path==='') { $path=$this->app->basePath('storage/backups/private'); }
        if (!is_string($path) || !str_starts_with($path,'/') || str_contains($path,"\0") || is_link($path)) { throw new DatabaseException('Backup directory must be an absolute private directory.'); }
        if (!is_dir($path) && !mkdir($path,0700,true)) { throw new DatabaseException('Cannot create backup directory.'); }
        $real=realpath($path); $public=realpath($this->app->basePath('public'));
        if ($real===false || $real==='/' || ($public!==false && ($real===$public || str_starts_with($real,$public.'/'))) || (fileperms($real)&0077)!==0 || !is_writable($real)) { throw new DatabaseException('Backup directory must be private (0700), writable and outside public/.'); }
        return $real;
    }
    private function location(string $id): string
    {
        if (!preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$id)) { throw new DatabaseException('Invalid backup ID.'); }
        $path=$this->root().'/'.$id;
        if (!is_dir($path) || is_link($path) || (fileperms($path)&0077)!==0) { throw new DatabaseException('Backup directory missing or unsafe.'); }
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
        if (proc_close($process)!==0) { throw new DatabaseException($tool.' failed; check client version, permissions, disk space and PostgreSQL logs.'); }
    }
    private function regular(string $path): void
    {
        if (!is_file($path)||is_link($path)||!is_readable($path)||(fileperms($path)&0077)!==0) { throw new DatabaseException('Backup file missing or unsafe.'); }
    }
    public function create(): array
    {
        $root=$this->root(); $id='backup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)); $temp=$root.'/.'.$id.'.partial';
        if (!mkdir($temp,0700)) { throw new DatabaseException('Cannot create temporary backup.'); }
        $locked=false;
        try {
            $pdo=$this->app->container()->get(PDO::class); $schema=$this->app->container()->get(ConnectionFactory::class)->schema(); $db=$this->app->config()->get('database.database');
            $lock=$pdo->prepare('SELECT pg_advisory_lock_shared(hashtextextended(:key,0))'); $lock->execute(['key'=>'cajeerlogs:migrations:'.$schema]); $lock->closeCursor(); $locked=true;
            $this->app->container()->get(MigrationRunner::class)->assertCurrent();
            $archive=$temp.'/database.dump';
            // Pre-create with mode 0600; pg_dump writes only to our private directory.
            $f=fopen($archive,'xb'); if ($f===false) { throw new DatabaseException('Cannot create backup archive.'); } fclose($f); chmod($archive,0600);
            $this->process(['pg_dump','--no-password','--format=custom','--no-owner','--no-privileges','--strict-names','--lock-wait-timeout=10s','--schema='.$schema,'--file='.$archive],$db);
            $this->regular($archive); $handle=fopen($archive,'r+b'); if ($handle===false) { throw new DatabaseException('Cannot synchronize backup archive.'); } try { if (!fsync($handle)) { throw new DatabaseException('Cannot synchronize backup archive.'); } } finally { fclose($handle); }
            $size=filesize($archive); $hash=hash_file('sha256',$archive);
            if (!$size||$hash===false) { throw new DatabaseException('Backup archive is empty or unreadable.'); }
            $this->process(['pg_restore','--list',$archive],$db);
            $meta=['format'=>1,'id'=>$id,'version'=>$this->app->version(),'schema'=>$schema,'created_at'=>gmdate(DATE_ATOM),'bytes'=>$size,'sha256'=>$hash];
            $file=$temp.'/manifest.json'; $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
            if (file_put_contents($file,$json)!==strlen($json)||!chmod($file,0600)) { throw new DatabaseException('Cannot write backup manifest.'); }
            $handle=fopen($file,'r+b'); if ($handle===false) { throw new DatabaseException('Cannot synchronize manifest.'); } try { if (!fsync($handle)) { throw new DatabaseException('Cannot synchronize manifest.'); } } finally { fclose($handle); }
            if (!rename($temp,$root.'/'.$id)) { throw new DatabaseException('Cannot publish backup.'); }
            return $meta;
        } finally {
            if ($locked) { $unlock=$pdo->prepare('SELECT pg_advisory_unlock_shared(hashtextextended(:key,0))'); $unlock->execute(['key'=>'cajeerlogs:migrations:'.$schema]); }
            if (is_dir($temp)) { foreach (['database.dump','manifest.json'] as $file) { if (is_file($temp.'/'.$file)) { unlink($temp.'/'.$file); } } rmdir($temp); }
        }
    }
    public function verify(string $id): array
    {
        $path=$this->location($id); $manifest=$path.'/manifest.json'; $archive=$path.'/database.dump'; $this->regular($manifest); $this->regular($archive);
        if (filesize($manifest)>16384) { throw new DatabaseException('Invalid backup manifest.'); }
        $m=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR); clearstatcache(true,$archive);
        if (!is_array($m)||($m['format']??null)!==1||($m['id']??null)!==$id||!is_int($m['bytes']??null)||$m['bytes']!==filesize($archive)||!is_string($m['sha256']??null)||!hash_equals($m['sha256'],hash_file('sha256',$archive))||!is_string($m['schema']??null)||!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$m['schema'])) { throw new DatabaseException('Backup checksum or manifest mismatch.'); }
        $this->process(['pg_restore','--list',$archive],$this->app->config()->get('database.database'));
        return $m;
    }
    /** These explicit releases share SQL 0001–0008; restored history is checked separately. */
    public static function compatibleRelease(mixed $backup, string $current): bool
    {
        return is_string($backup) && ($backup === $current || ($current === '0.1.0' && $backup === '0.0.9') || ($current === '0.1.1' && in_array($backup,['0.0.9','0.1.0'],true)) || ($current === '0.1.2' && in_array($backup,['0.0.9','0.1.0','0.1.1'],true)) || ($current === '0.1.3' && in_array($backup,['0.0.9','0.1.0','0.1.1','0.1.2'],true)) || ($current === '0.1.4' && in_array($backup,['0.0.9','0.1.0','0.1.1','0.1.2','0.1.3'],true)) || ($current === '0.1.5' && in_array($backup,['0.0.9','0.1.0','0.1.1','0.1.2','0.1.3','0.1.4'],true)));
    }
    public function restore(string $id,string $target): array
    {
        $m=$this->verify($id); $c=$this->app->config();
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
        // Dedicated target only; --clean handles pre-created public schema, never the live database.
        $this->process(['pg_restore','--no-password','--exit-on-error','--single-transaction','--clean','--if-exists','--no-owner','--no-privileges','--dbname='.$target,$this->location($id).'/database.dump'],$target);
        $targetConfig->set('database.schema',$m['schema']); $restored=(new ConnectionFactory($targetConfig))->make('pgsql');
        $runner=new MigrationRunner($restored,$this->app->basePath('migrations/pgsql'),$m['schema']);
        if ($runner->status()['pending']!==[]) { throw new DatabaseException('Restored migration history incomplete; do not switch production to this database.'); }
        $runner->migrate(); // Validate and adopt legacy schema without trusting a stale catalog.
        $runner->assertCurrent();
        // Old snapshots must not resurrect revoked credentials. Reconcile external deliveries before starting workers.
        (new TransactionManager($restored))->run(fn()=> $restored->exec('DELETE FROM auth_sessions; DELETE FROM user_recovery_codes; UPDATE api_tokens SET revoked_at=now() WHERE revoked_at IS NULL'), maxAttempts: 3);
        return ['id'=>$id,'database'=>$target,'schema'=>$m['schema'],'status'=>'restored','sessions_revoked'=>true,'api_tokens_revoked'=>true,'recovery_codes_invalidated'=>true];
    }
    public function list(): array
    {
        $root=$this->root(); $ids=[];
        foreach (scandir($root)?:[] as $id) { if (preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$id)&&is_dir($root.'/'.$id)&&!is_link($root.'/'.$id)) { $ids[]=$id; } }
        rsort($ids,SORT_STRING); return $ids;
    }
}
