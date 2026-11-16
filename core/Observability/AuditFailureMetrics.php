<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Installer\SecureFile;

/** Process-shared counter of failed audit writes, independent of the DB transaction being rolled back. */
final class AuditFailureMetrics
{
    public function __construct(private readonly string $directory) {}
    private function path(): string { return $this->directory.'/audit-failures'; }
    private function read(): int
    {
        SecureFile::assertDirectory($this->directory);
        $path=$this->path(); SecureFile::assertPrivate($path,true);
        if (!is_file($path)) return 0;
        $f=fopen($path,'rb'); if ($f===false) throw new \RuntimeException('Audit metric unavailable.');
        try { SecureFile::verifyHandle($f,$path); $raw=fread($f,64); } finally {fclose($f);}
        if (!is_string($raw)||!preg_match('/^(?:0|[1-9][0-9]{0,18})\n$/D',$raw)||filter_var(trim($raw),FILTER_VALIDATE_INT)===false) throw new \RuntimeException('Invalid audit metric.');
        return (int)$raw;
    }
    public function increment(): void
    {
        $lock=null; $tmp=null;
        try {
            SecureFile::assertDirectory($this->directory);
            $path=$this->path(); $lockPath=$path.'.lock'; SecureFile::assertPrivate($lockPath,true);
            $old=umask(0077); try {$lock=fopen($lockPath,'c+b');} finally {umask($old);}
            if ($lock===false) return;
            SecureFile::verifyHandle($lock,$lockPath);
            if (!flock($lock,LOCK_EX)) return;
            $count=$this->read(); $next=$path.'.next'; SecureFile::assertPrivate($next,true); $tmp=$next;
            $old=umask(0077); try {$f=fopen($tmp,'c+b');} finally {umask($old);}
            if ($f===false) return;
            try {
                SecureFile::verifyHandle($f,$tmp);
                $raw=(string)min(PHP_INT_MAX,$count===PHP_INT_MAX?$count:$count+1)."\n";
                if (!ftruncate($f,0)||fwrite($f,$raw)!==strlen($raw)||!fflush($f)) return;
            } finally {fclose($f);}
            if (!rename($tmp,$path)) return; $tmp=null;
        } catch (\Throwable) { /* Never mask the original audit error. */ }
        finally { if ($tmp!==null && is_resource($lock)) @unlink($tmp); if (is_resource($lock)) fclose($lock); }
    }
    public function render(): string
    {
        try { $count=$this->read(); return "# TYPE cajeerlogs_audit_metrics_available gauge\ncajeerlogs_audit_metrics_available 1\n# TYPE cajeerlogs_audit_write_failures_total counter\ncajeerlogs_audit_write_failures_total $count\n"; }
        catch (\Throwable) { return "# TYPE cajeerlogs_audit_metrics_available gauge\ncajeerlogs_audit_metrics_available 0\n"; }
    }
}
