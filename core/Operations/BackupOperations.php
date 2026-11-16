<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\SecureFile;
use Cajeer\Logs\Kernel\Application;

/** Durable operator-side history and offsite replication, independent of the restored database. */
final class BackupOperations
{
    private BackupManager $manager;
    public function __construct(private readonly Application $app) { $this->manager=new BackupManager($app); }
    private function config(string $key): mixed { return $this->app->config()->get('operations.'.$key); }
    public function locked(callable $fn,bool $create=true): mixed {
        $path=$this->manager->root($create).'/.operations.lock'; SecureFile::assertPrivate($path,true);
        $old=umask(0077); try { $f=fopen($path,'c+b'); } finally { umask($old); }
        if ($f===false) throw new DatabaseException('Cannot open backup lock.');
        try { SecureFile::verifyHandle($f,$path); if (!flock($f,LOCK_EX|LOCK_NB)) throw new DatabaseException('Another backup operation is running.'); return $fn(); }
        finally { fclose($f); }
    }
    private function remote(): string {
        $path=$this->config('backup_offsite_dir'); $local=$this->manager->root();
        if (!is_string($path)||$path===''||!str_starts_with($path,'/')||!SecureFile::directory($path)|| (fileperms($path)&0077)!==0) throw new DatabaseException('Offsite directory must exist and be private, canonical and writable.');
        $path=rtrim($path,'/'); $public=realpath($this->app->basePath('public'));
        if ($path==='/'||$path===$local||str_starts_with($path,$local.'/')||str_starts_with($local,$path.'/')||($public!==false&&($path===$public||str_starts_with($path,$public.'/')))) throw new DatabaseException('Offsite directory must be separate from local backup and public directories.');
        $a=stat($local); $b=stat($path);
        if ($a===false||$b===false||($this->config('backup_offsite_distinct_device') && $a['dev']===$b['dev'])) throw new DatabaseException('Offsite backup requires a distinct filesystem; check the mount.');
        return $path;
    }
    private function vault(): BackupVault { return new BackupVault(BackupVault::key($this->config('backup_encryption_key'))); }
    private function id(string $id): void { if (!preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$id)) throw new DatabaseException('Invalid backup ID.'); }
    private function package(string $id): string { $this->id($id); return $this->remote().'/'.$id.'.cjr'; }
    private function atomicJson(string $path,array $data): void {
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp'; $old=umask(0077); $f=null;
        try {
            $f=fopen($tmp,'xb'); if ($f===false) throw new DatabaseException('Cannot create backup metadata.');
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
            if (fwrite($f,$json)!==strlen($json)||!fflush($f)||!fsync($f)) throw new DatabaseException('Cannot write backup metadata.');
            fclose($f); $f=null; if (!rename($tmp,$path)) throw new DatabaseException('Cannot publish backup metadata.');
            BackupManager::syncDirectory(dirname($path));
        } finally { if (is_resource($f)) fclose($f); @unlink($tmp); umask($old); }
    }
    private function journal(): string {
        $p=$this->manager->root().'/.history'; if (!is_dir($p)&&!mkdir($p,0700)) throw new DatabaseException('Cannot create backup history.');
        if (!SecureFile::directory($p)||(fileperms($p)&0077)!==0) throw new DatabaseException('Unsafe backup history directory.'); return $p;
    }
    private function state(): array {
        $p=$this->manager->root().'/.schedule.json'; if (!file_exists($p)&&!is_link($p)) return [];
        SecureFile::assertPrivate($p); if (filesize($p)>16384) throw new DatabaseException('Invalid backup schedule state.');
        $s=json_decode(file_get_contents($p),true,8,JSON_THROW_ON_ERROR); if (!is_array($s)) throw new DatabaseException('Invalid backup schedule state.'); return $s;
    }
    private function save(array $s): void { $this->atomicJson($this->manager->root().'/.schedule.json',$s); }
    /** Read-only diagnostic: no history or backup directory is created by HTTP. */
    public function status(): array {
        $path=$this->config('backup_dir') ?: $this->app->basePath('storage/backups/private');
        $s=[];
        if (is_dir($path)) { $this->manager->root(false); $s=$this->state(); }
        return ['interval_seconds'=>$this->config('backup_interval_seconds'),'offsite_configured'=>$this->config('backup_offsite_dir')!=='',
            'last_success_at'=>isset($s['last_success_epoch'])?gmdate(DATE_ATOM,$s['last_success_epoch']):null,'last_success_seconds'=>$s['last_success_seconds']??null,
            'last_offsite_verified'=>$s['last_offsite_verified']??false,'replication_pending'=>isset($s['pending_id']),
            'last_restore_at'=>isset($s['last_restore_epoch'])?gmdate(DATE_ATOM,$s['last_restore_epoch']):null,'last_restore_seconds'=>$s['last_restore_seconds']??null];
    }
    public function history(int $limit=50): array {
        $dir=$this->journal(); $files=glob($dir.'/run-*.json')?:[]; rsort($files,SORT_STRING); $rows=[];
        foreach (array_slice($files,0,$limit) as $f) { SecureFile::assertPrivate($f); if (filesize($f)>16384) throw new DatabaseException('Invalid backup history record.'); $rows[]=json_decode(file_get_contents($f),true,8,JSON_THROW_ON_ERROR); }
        return ['state'=>$this->state(),'runs'=>$rows];
    }
    public function record(string $operation,callable $fn): array {
        $start=microtime(true); $id='run-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)); $path=$this->journal().'/'.$id.'.json';
        $row=['id'=>$id,'operation'=>$operation,'started_at'=>gmdate(DATE_ATOM),'status'=>'running']; $this->atomicJson($path,$row);
        try { $result=$fn(); $row['status']='success'; $row['result']=$result; return $result; }
        catch (\Throwable $e) { $row['status']='failed'; $row['error']='operation_failed'; throw $e; }
        finally { $row['finished_at']=gmdate(DATE_ATOM); $row['duration_seconds']=round(microtime(true)-$start,3); $this->atomicJson($path,$row); }
    }
    public function replicate(string $id): array {
        $m=$this->manager->verify($id); $path=$this->package($id); $vault=$this->vault();
        if (!file_exists($path)&&!is_link($path)) {
            $temp=$path.'.'.bin2hex(random_bytes(8)).'.partial';
            try {
                $vault->encrypt($this->manager->location($id).'/database.dump',$m,$temp);
                $verified=$vault->decrypt($temp,null,$id); if ($verified!==$m) throw new DatabaseException('Offsite manifest mismatch.');
                // link is atomic and cannot overwrite an existing independent copy.
                if (!@link($temp,$path)) throw new DatabaseException('Cannot publish offsite backup without overwriting.');
                unlink($temp); BackupManager::syncDirectory(dirname($path));
            } finally { @unlink($temp); }
        }
        $verified=$vault->decrypt($path,null,$id); if ($verified!==$m) throw new DatabaseException('Existing offsite backup differs from local backup.');
        return ['id'=>$id,'sha256'=>$m['sha256'],'offsite_verified'=>true];
    }
    public function offsiteList(): array {
        $ids=[]; $root=$this->remote();
        foreach (scandir($root)?:[] as $name) {
            if (preg_match('/^(backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\.cjr$/D',$name,$match)&&SecureFile::privateFile($root.'/'.$name)) $ids[]=$match[1];
        }
        rsort($ids,SORT_STRING); return $ids;
    }
    public function offsiteVerify(string $id): array { $m=$this->vault()->decrypt($this->package($id),null,$id); return $m+['offsite_verified'=>true]; }
    public function fetch(string $id): array {
        $this->id($id); $root=$this->manager->root(); $free=disk_free_space($root); if ($free===false||$free<$this->config('backup_min_free_bytes')) throw new DatabaseException('Insufficient free space for backup import.'); $target=$root.'/'.$id; if (file_exists($target)||is_link($target)) throw new DatabaseException('Local backup already exists; refusing overwrite.');
        $tmp=$root.'/.'.$id.'.'.bin2hex(random_bytes(8)).'.partial'; if (!mkdir($tmp,0700)) throw new DatabaseException('Cannot create import directory.');
        try {
            $m=$this->vault()->decrypt($this->package($id),$tmp.'/database.dump',$id);
            if (!BackupManager::compatibleRelease($m['version'],$this->app->version())) throw new DatabaseException('Unsupported offsite backup release.');
            $this->atomicJson($tmp.'/manifest.json',$m); $this->manager->verifyDirectory($tmp,$id);
            if (!rename($tmp,$target)) throw new DatabaseException('Cannot publish imported backup.'); BackupManager::syncDirectory($root);
            return $m+['imported'=>true];
        } finally { if (is_dir($tmp)) { @unlink($tmp.'/database.dump'); @unlink($tmp.'/manifest.json'); @rmdir($tmp); } }
    }
    public function run(): array {
        $s=$this->state(); $now=time(); $interval=$this->config('backup_interval_seconds');
        if (($s['pending_id']??null)===null && ($s['last_success_epoch']??0)+$interval>$now) return ['status'=>'not_due','next_run_at'=>gmdate(DATE_ATOM,$s['last_success_epoch']+$interval)];
        $started=hrtime(true);
        return $this->record('scheduled_backup',function() use ($s,$started) {
            if (($s['pending_requires_offsite']??false) && $this->config('backup_offsite_dir')==='') throw new DatabaseException('Pending backup requires offsite replication; restore its configuration.');
            if ($this->config('backup_offsite_dir')!=='') { $this->remote(); $this->vault(); }
            if (isset($s['pending_id'])) { $m=$this->manager->verify($s['pending_id']); }
            else { $m=$this->manager->create(); $s['pending_id']=$m['id']; $s['pending_requires_offsite']=$this->config('backup_offsite_dir')!==''; $this->save($s); }
            $remote=$this->config('backup_offsite_dir')!==''?$this->replicate($m['id']):null;
            $s['last_success_epoch']=time(); $s['last_success_seconds']=round((hrtime(true)-$started)/1e9,3); $s['last_success_id']=$m['id']; $s['last_offsite_verified']=$remote!==null; unset($s['pending_id'],$s['pending_requires_offsite']); $this->save($s);
            return ['status'=>'completed','backup'=>$m,'offsite'=>$remote];
        });
    }
    public function rehearse(string $id,string $target): array {
        return $this->record('restore_rehearsal',function() use ($id,$target) {
            $start=hrtime(true); $r=$this->manager->restore($id,$target); $r['duration_seconds']=round((hrtime(true)-$start)/1e9,3);
            $s=$this->state(); $s['last_restore_epoch']=time(); $s['last_restore_id']=$id; $s['last_restore_seconds']=$r['duration_seconds']; $this->save($s); return $r;
        });
    }
    public function prune(bool $confirm): array {
        $keep=$this->config('backup_keep_local'); if ($keep<1) throw new DatabaseException('Set BACKUP_KEEP_LOCAL before pruning.');
        $ids=$this->manager->list(); $protected=array_slice($ids,0,$keep);
        foreach (['daily'=>'Y-m-d','weekly'=>'o-W','monthly'=>'Y-m'] as $period=>$format) {
            $limit=$this->config('backup_keep_'.$period); $buckets=[];
            foreach ($ids as $id) {
                if (count($buckets)>=$limit) break;
                $date=\DateTimeImmutable::createFromFormat('!Ymd\THis\Z',substr($id,7,16),new \DateTimeZone('UTC'));
                if ($date===false) continue;
                $bucket=$date->format($format); if (!isset($buckets[$bucket])) { $buckets[$bucket]=true; $protected[]=$id; }
            }
        }
        $s=$this->state();
        foreach (['pending_id','last_success_id','last_restore_id'] as $key) if (isset($s[$key])) $protected[]=$s[$key];
        $candidates=array_values(array_diff($ids,$protected)); $removed=[]; $skipped=[];
        foreach ($candidates as $id) {
            // Only delete a verified local copy with a fully authenticated matching offsite copy.
            try { $this->replicateCheck($id); } catch (\Throwable) { $skipped[]=$id; continue; }
            if (!$confirm) continue;
            $path=$this->manager->location($id); if (array_values(array_diff(scandir($path)?:[],['.','..','database.dump','manifest.json']))!==[]) throw new DatabaseException('Unexpected files in backup directory; refusing prune.'); $trash=$this->manager->root().'/.'.$id.'.pruning';
            if (file_exists($trash)||!rename($path,$trash)) throw new DatabaseException('Cannot quarantine local backup for pruning.');
            BackupManager::syncDirectory($this->manager->root());
            if (!unlink($trash.'/database.dump')||!unlink($trash.'/manifest.json')||!rmdir($trash)) throw new DatabaseException('Cannot finish local backup pruning.');
            $removed[]=$id;
        }
        return ['dry_run'=>!$confirm,'protected'=>array_values(array_unique($protected)),'eligible'=>array_values(array_diff($candidates,$skipped)),'skipped'=>$skipped,'removed'=>$removed];
    }
    private function replicateCheck(string $id): void { $m=$this->manager->verify($id); $r=$this->vault()->decrypt($this->package($id),null,$id); if ($r!==$m) throw new DatabaseException('Offsite backup differs.'); }
}
