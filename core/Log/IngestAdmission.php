<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;

/** Per-storage concurrency slots, released by the OS even after a process crash. */
final class IngestAdmission
{
    private function __construct(private mixed $handle) {}
    public static function acquire(string $directory,int $slots): self
    {
        if ($slots===0) return new self(null);
        // The directory is private application storage, never a web-accessible path.
        for ($i=0;$i<$slots;$i++) {
            $path=$directory.'/ingest-slot-'.$i.'.lock';
            if (is_link($path) || (file_exists($path) && !is_file($path))) throw IngestBudget::unavailable();
            $handle=@fopen($path,'c+b');
            if ($handle===false) throw IngestBudget::unavailable();
            if (flock($handle,LOCK_EX|LOCK_NB)) return new self($handle);
            fclose($handle);
        }
        throw new HttpException(503,'ingest_busy','Ingest capacity reached.',['Retry-After'=>'1']);
    }
    public function release(): void { if (is_resource($this->handle)) { fclose($this->handle); $this->handle=null; } }
    public function __destruct() { $this->release(); }
}
