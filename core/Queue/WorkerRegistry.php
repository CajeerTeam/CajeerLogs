<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;
use Cajeer\Logs\Installer\SecureFile;

/** Local/shared-storage registry; unique process IDs, no credentials or payloads. */
final class WorkerRegistry
{
    private ?string $path=null;
    private array $entry=[];
    private int $lastWrite=0;
    public function __construct(private readonly string $directory) {}
    public static function host(): string { return substr(hash('sha256',gethostname()?:'unknown'),0,16); }
    private static function processStart(int $pid): ?string
    {
        if ($pid<=0) return null;
        $raw=@file_get_contents('/proc/'.$pid.'/stat',false,null,0,4096);
        if (!is_string($raw)||($end=strrpos($raw,')'))===false) return null;
        $fields=explode(' ',trim(substr($raw,$end+1)));
        return isset($fields[19]) && ctype_digit($fields[19]) && $fields[0]!=='Z' ? $fields[19] : null;
    }
    public static function localAlive(array $worker): bool
    {
        if (($worker['host']??null)!==self::host()||!is_int($worker['pid']??null)||$worker['pid']<=0) return false;
        if (isset($worker['process_start'])) return self::processStart($worker['pid'])===$worker['process_start'];
        return function_exists('posix_kill')&&@posix_kill($worker['pid'],0);
    }
    public function start(int $staleAfter): void
    {
        $this->close();$this->lastWrite=0;
        try {
            SecureFile::assertDirectory($this->directory);
            $id=bin2hex(random_bytes(16));
            $pool=getenv('CAJEER_QUEUE_POOL_ID');
            $this->entry=['id'=>$id,'host'=>self::host(),'pool'=>is_string($pool)&&preg_match('/^[a-f0-9]{32}$/D',$pool)?$pool:null,'pid'=>getmypid(),'process_start'=>self::processStart(getmypid()),'started_at'=>time(),'last_success'=>0,'stale_after'=>$staleAfter,'processed'=>0,'state'=>'starting'];
            $this->path=$this->directory.'/queue-worker-'.$id.'.json';
            $old=umask(0077); try {$f=@fopen($this->path,'x+b');} finally {umask($old);}
            if ($f===false) {$this->path=null;return;}
            try { SecureFile::verifyHandle($f,$this->path); $this->write($f); } finally {fclose($f);}
            // Old crashed-process markers are never reused. Keep a bounded scan for cleanup.
            $this->snapshot(true);
        } catch (\Throwable) { $this->close(); }
    }
    private function write($f): void
    {
        if (!flock($f,LOCK_EX)) throw new \RuntimeException('Worker marker lock failed.');
        try {
            $raw=json_encode($this->entry,JSON_THROW_ON_ERROR)."\n";
            if (!ftruncate($f,0)||!rewind($f)||fwrite($f,$raw)!==strlen($raw)||!fflush($f)) throw new \RuntimeException('Worker marker write failed.');
        } finally {flock($f,LOCK_UN);}
    }
    public function progress(int $processed): void
    {
        if ($this->path===null) return;
        $now=time(); if ($now-$this->lastWrite<5) return;
        try {
            SecureFile::assertPrivate($this->path); $f=@fopen($this->path,'r+b'); if ($f===false) return;
            try {SecureFile::verifyHandle($f,$this->path); $this->entry['last_success']=$now;$this->entry['processed']=$processed;$this->entry['state']='active';$this->write($f);$this->lastWrite=$now;} finally {fclose($f);}
        } catch (\Throwable) { /* Observability must not interrupt committed work. */ }
    }
    public function close(): void
    {
        if ($this->path!==null) {try {SecureFile::assertPrivate($this->path);@unlink($this->path);} catch (\Throwable) {} $this->path=null;}
    }
    public function snapshot(bool $prune=false): array
    {
        $workers=[];$invalid=0;$truncated=false;$now=time();$seen=0;
        try {
            SecureFile::assertDirectory($this->directory);
            foreach (new \DirectoryIterator($this->directory) as $file) {
                $name=$file->getFilename(); if (!preg_match('/^queue-worker-([a-f0-9]{32})\.json$/D',$name,$match)) continue;
                if (++$seen>1024) {$truncated=true;break;}
                $path=$file->getPathname();
                try {
                    SecureFile::assertPrivate($path);$f=@fopen($path,$prune?'r+b':'rb');if ($f===false) continue;
                    try {
                        $stat=SecureFile::verifyHandle($f,$path);
                        if (!flock($f,($prune?LOCK_EX:LOCK_SH)|LOCK_NB)) {$invalid++;continue;}
                        if ($prune && $stat['mtime']<$now-86400) {
                            $stat=SecureFile::verifyHandle($f,$path);
                            if ($stat['mtime']<$now-86400) { @unlink($path);continue; }
                        }
                        $raw=fread($f,2049);$w=json_decode($raw?:'',true,8,JSON_THROW_ON_ERROR);
                        if (!is_array($w)||($w['id']??null)!==$match[1]||!is_int($w['started_at']??null)||!is_int($w['last_success']??null)||!is_int($w['stale_after']??null)||$w['stale_after']<120||$w['stale_after']>3630||$w['started_at']<1||$w['started_at']>$now+5||$w['last_success']<0||$w['last_success']>$now+5||!is_int($w['processed']??null)||$w['processed']<0||!is_int($w['pid']??null)||$w['pid']<1|| (isset($w['process_start'])&&(!is_string($w['process_start'])||!ctype_digit($w['process_start'])))||!is_string($w['host']??null)||!preg_match('/^[a-f0-9]{16}$/D',$w['host'])||(!is_null($w['pool']??null)&&(!is_string($w['pool'])||!preg_match('/^[a-f0-9]{32}$/D',$w['pool'])))) throw new \RuntimeException('Invalid worker marker.');
                        if ($prune && max($w['started_at'],$w['last_success'])<$now-86400) {SecureFile::verifyHandle($f,$path);@unlink($path);continue;}
                        $w=array_intersect_key($w,array_flip(['id','host','pool','pid','process_start','started_at','last_success','stale_after','processed']));
                        $w['pool']=$w['pool']??null;
                        $w['status']=$w['last_success']===0?'starting':($now-$w['last_success']>$w['stale_after']?'stale':'ok');
                        if ($w['status']==='starting' && $now-$w['started_at']>$w['stale_after']) $w['status']='stale';
                        if ($w['host']===self::host()&&!self::localAlive($w)) $w['status']='stale';
                        $workers[]=$w;
                    } finally {fclose($f);}
                } catch (\Throwable) { $invalid++; }
            }
        } catch (\Throwable) {return ['available'=>false,'active'=>0,'workers'=>[],'invalid'=>0,'truncated'=>false];}
        return ['available'=>true,'active'=>count(array_filter($workers,fn($w)=>$w['status']==='ok')),'workers'=>$workers,'invalid'=>$invalid,'truncated'=>$truncated];
    }
}
