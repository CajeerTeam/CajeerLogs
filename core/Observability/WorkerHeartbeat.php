<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Installer\SecureFile;

/** One bounded, process-shared progress marker per worker role. Never marks a failed pass successful. */
final class WorkerHeartbeat
{
    public const ROLES=['queue'=>120,'scheduler'=>180,'webhook'=>420,'backup'=>14400];
    private array $last=[];
    public function __construct(private readonly string $directory) {}
    public function record(string $role): void
    {
        if (!isset(self::ROLES[$role])) throw new \InvalidArgumentException('Unknown worker role.');
        $now=time(); if (($this->last[$role]??0)>=$now-5) return;
        $lock=null; $temp=null;
        try {
            SecureFile::assertDirectory($this->directory);
            $path=$this->directory.'/heartbeat-'.$role;
            $lockPath=$path.'.lock'; SecureFile::assertPrivate($lockPath,true);
            $old=umask(0077); try { $lock=@fopen($lockPath,'c+b'); } finally {umask($old);}
            if ($lock===false) return;
            SecureFile::verifyHandle($lock,$lockPath);
            if (!flock($lock,LOCK_EX|LOCK_NB)) return;
            SecureFile::assertPrivate($path,true);
            $next=$path.'.next'; SecureFile::assertPrivate($next,true); $temp=$next;
            $old=umask(0077); try { $f=@fopen($temp,'c+b'); } finally {umask($old);}
            if ($f===false) return;
            try {
                SecureFile::verifyHandle($f,$temp);
                $raw=(string)$now."\n";
                if (!ftruncate($f,0)||fwrite($f,$raw)!==strlen($raw)||!fflush($f)) return;
            } finally { fclose($f); }
            if (!rename($temp,$path)) return;
            $temp=null; $this->last[$role]=$now;
        } catch (\Throwable) { /* Observation must not interrupt committed work. */ }
        finally { if ($temp!==null && is_resource($lock)) @unlink($temp); if (is_resource($lock)) fclose($lock); }
    }
    public function snapshot(?int $now=null): array
    {
        $now??=time(); $result=[];
        foreach (self::ROLES as $role=>$threshold) {
            $epoch=0; $valid=true; $path=$this->directory.'/heartbeat-'.$role;
            try {
                SecureFile::assertDirectory($this->directory);
                SecureFile::assertPrivate($path,true);
                if (is_file($path)) {
                    $f=fopen($path,'rb'); if ($f===false) throw new \RuntimeException('Unreadable heartbeat.');
                    try { SecureFile::verifyHandle($f,$path); $raw=fread($f,32); } finally {fclose($f);}
                    if (!is_string($raw)||!preg_match('/^[1-9][0-9]{0,11}\n$/D',$raw)|| (int)$raw>$now+5) throw new \RuntimeException('Invalid heartbeat.');
                    $epoch=(int)$raw;
                }
            } catch (\Throwable) {$valid=false;}
            $age=$epoch>0?max(0,$now-$epoch):null;
            $result[$role]=['last_success_timestamp'=>$epoch,'age_seconds'=>$age,'stale_after_seconds'=>$threshold,'status'=>!$valid?'unavailable':($epoch===0?'never':($age>$threshold?'stale':'ok'))];
        }
        return $result;
    }
}
