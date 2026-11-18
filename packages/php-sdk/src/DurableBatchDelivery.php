<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/**
 * Crash-recoverable PHP SDK batch delivery. Requires only PHP filesystem functions.
 * Every event is durably spooled before enqueue returns; an immutable batch containing
 * the exact HTTP body and idempotency key is durably committed before network I/O.
 * A receiver must implement the API idempotency contract (within its retention window).
 */
final class DurableBatchDelivery
{
    private readonly string $directory;
    private readonly string $ready;
    private readonly string $batches;
    private readonly string $stateLock;
    private readonly string $flushLock;
    private ?DeliveryException $lastFailure = null;

    public function __construct(
        private readonly CajeerLogsClient $client,
        string $spoolDirectory,
        private readonly int $batchSize = 25,
        private readonly int $maxPendingEvents = 10000,
        private readonly int $maxBatchBytes = 1048576,
        private readonly bool $spoolOnDeliveryFailure = false,
    ) {
        if ($batchSize < 1 || $batchSize > 100 || $maxPendingEvents < $batchSize || $maxPendingEvents > 100000
            || $maxBatchBytes < 65536 || $maxBatchBytes > 1048576
            || $spoolDirectory === '' || str_contains($spoolDirectory, "\0")
            || !(str_starts_with($spoolDirectory, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $spoolDirectory))) {
            throw new \InvalidArgumentException('Invalid durable batch configuration.');
        }
        $this->directory = rtrim($spoolDirectory, '/\\');
        $this->ready = $this->directory.'/ready';
        $this->batches = $this->directory.'/batches';
        foreach ([$this->directory,$this->ready,$this->batches] as $directory) {
            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) throw new \RuntimeException('Unsafe spool directory.');
            if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create spool directory.');
            if (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0077) !== 0) throw new \RuntimeException('Spool directory must be private (0700).');
        }
        $this->stateLock = $this->directory.'/.state.lock';
        $this->flushLock = $this->directory.'/.flush.lock';
        foreach ([$this->stateLock,$this->flushLock] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException('Unsafe spool lock.');
            $oldUmask=umask(0077);
            try { $handle = @fopen($path,'cb'); } finally { umask($oldUmask); }
            if (!$handle) throw new \RuntimeException('Cannot open spool lock.');
            @chmod($path,0600);
            fclose($handle);
            if (is_link($path) || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077)!==0)) throw new \RuntimeException('Spool lock must be private.');
        }
    }

    /** Persist before returning. Automatically sends when a full microbatch is available. */
    public function enqueue(array $event): void
    {
        $json = CajeerLogsClient::prepareEvent($event); // validates before touching disk
        if (strlen($json) + strlen('{"events":[]}') > $this->maxBatchBytes) {
            throw new \InvalidArgumentException('Event cannot fit the configured batch byte limit.');
        }
        $flush = $this->withStateLock(function () use ($json): bool {
            [$pending,$ready] = $this->counts();
            if ($pending >= $this->maxPendingEvents) throw new \OverflowException('Durable delivery spool capacity exceeded; event was not accepted.');
            $id = sprintf('%020d', (int)(microtime(true)*1000000)).'-'.bin2hex(random_bytes(8));
            $this->atomicWrite($this->ready.'/'.$id.'.json',$json);
            return $ready + 1 >= $this->batchSize;
        });
        if ($flush) $this->flush();
    }

    /**
     * Deliver at most $maxBatches (0 = drain all). On failure the immutable batch
     * stays on disk. In spool mode the caller can inspect lastFailure() and retry.
     * Returns the number of events whose HTTP receipt was validated.
     */
    public function flush(int $maxBatches = 0): int
    {
        if ($maxBatches < 0 || $maxBatches > 100000) throw new \InvalidArgumentException('Invalid batch limit.');
        $confirmed = 0;
        $this->lastFailure = null;
        $lock = $this->openLock($this->flushLock);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock spool flush.');
            for ($i=0; $maxBatches===0 || $i<$maxBatches; $i++) {
                $batch = $this->withStateLock(fn() => $this->nextBatch());
                if ($batch === null) break;
                try {
                    $this->client->sendBody($batch['body'],$batch['key']);
                } catch (DeliveryException $e) {
                    $this->lastFailure = $e;
                    if (!$this->spoolOnDeliveryFailure) throw $e;
                    break;
                }
                $this->withStateLock(function () use ($batch): void {
                    $path = $this->batches.'/'.$batch['id'].'.json';
                    // Only the flush lock owner can retire an immutable batch.
                    if (!is_file($path) || !@unlink($path)) throw new \RuntimeException('Cannot retire confirmed spool batch.');
                });
                $confirmed += count($batch['events']);
            }
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
        return $confirmed;
    }

    /** Number of accepted-but-not-yet-confirmed events, including frozen batches. */
    public function pendingEvents(): int
    {
        return $this->withStateLock(fn(): int => $this->counts()[0]);
    }

    public function lastFailure(): ?DeliveryException { return $this->lastFailure; }

    private function withStateLock(callable $callback): mixed
    {
        $lock=$this->openLock($this->stateLock);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock spool state.');
            return $callback();
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLock(string $path)
    {
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('Unsafe spool lock.');
        $lock=@fopen($path,'cb');
        if (!$lock) throw new \RuntimeException('Cannot open spool lock.');
        return $lock;
    }

    private function files(string $directory): array
    {
        $files=glob($directory.'/*.json');
        if ($files===false) throw new \RuntimeException('Cannot enumerate durable spool.');
        sort($files,SORT_STRING);
        return $files;
    }

    /** @return array{int,int} */
    private function counts(): array
    {
        $pending=0;
        foreach ($this->files($this->batches) as $path) {
            $batch=$this->readBatch($path);
            $pending+=count($batch['events']);
            foreach ($batch['events'] as $id) {
                $source=$this->ready.'/'.$id.'.json';
                if (is_file($source) && !@unlink($source)) throw new \RuntimeException('Cannot reconcile committed spool batch.');
            }
        }
        $ready=$this->files($this->ready);
        return [$pending+count($ready),count($ready)];
    }

    private function nextBatch(): ?array
    {
        // A crash between committing a batch and deleting its source event files
        // can leave BOTH. The batch has precedence and its source records are retired
        // before any new batch is made. Never change a batch after first sending it.
        $existing = $this->files($this->batches);
        $first = null;
        foreach ($existing as $path) {
            $batch=$this->readBatch($path);
            foreach ($batch['events'] as $id) {
                $source=$this->ready.'/'.$id.'.json';
                if (is_file($source) && !@unlink($source)) throw new \RuntimeException('Cannot reconcile committed spool batch.');
            }
            $first ??= $batch;
        }
        if ($first!==null) return $first;
        $ready=$this->files($this->ready);
        if (!$ready) return null;
        $events=[];$json=[];$bytes=strlen('{"events":[]}');
        foreach ($ready as $path) {
            if (is_link($path) || !is_file($path) || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0)) throw new \RuntimeException('Unsafe spool entry.');
            $name=basename($path,'.json');
            if (!preg_match('/^[0-9]{20}-[a-f0-9]{16}$/D',$name)) throw new \RuntimeException('Invalid spool event name.');
            $event=@file_get_contents($path);
            if (!is_string($event) || strlen($event)>65536 || !json_validate($event,32)) throw new \RuntimeException('Corrupt spooled event.');
            $newBytes=$bytes+strlen($event)+(count($json)>0?1:0);
            if ($newBytes>$this->maxBatchBytes) {
                if (!$json) throw new \RuntimeException('Single event exceeds configured batch byte limit.');
                break;
            }
            $events[]=$name;$json[]=$event;$bytes=$newBytes;
            if (count($json)===$this->batchSize) break;
        }
        $body='{"events":['.implode(',',$json).']}';
        $id=sprintf('%020d',(int)(microtime(true)*1000000)).'-'.bin2hex(random_bytes(8));
        $batch=['format'=>1,'id'=>$id,'key'=>'php-batch-'.$id,'events'=>$events,'body'=>$body,'sha256'=>hash('sha256',$body)];
        $this->atomicWrite($this->batches.'/'.$id.'.json',json_encode($batch,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        foreach ($events as $eventId) {
            if (!@unlink($this->ready.'/'.$eventId.'.json')) throw new \RuntimeException('Cannot finalize durable spool batch.');
        }
        return $batch;
    }

    private function readBatch(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path)>2200000 || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0)) throw new \RuntimeException('Unsafe spool batch.');
        $raw=@file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('Cannot read spool batch.');
        $batch=json_decode($raw,true,12);
        if (!is_array($batch) || ($batch['format']??null)!==1 || !is_string($batch['id']??null)
            || basename($path)!==$batch['id'].'.json'
            || !preg_match('/^[0-9]{20}-[a-f0-9]{16}$/D',$batch['id'])
            || ($batch['key']??null)!=='php-batch-'.$batch['id'] || !is_array($batch['events']??null)
            || !array_is_list($batch['events']) || count($batch['events'])<1 || count($batch['events'])>100
            || count(array_unique($batch['events']))!==count($batch['events'])
            || !array_all($batch['events'],static fn($id): bool => is_string($id) && preg_match('/^[0-9]{20}-[a-f0-9]{16}$/D',$id)===1)
            || !is_string($batch['body']??null) || strlen($batch['body'])>1048576
            || !is_string($batch['sha256']??null) || !hash_equals(hash('sha256',$batch['body']),$batch['sha256'])) {
            throw new \RuntimeException('Corrupt immutable spool batch.');
        }
        $decoded=json_decode($batch['body'],false,32);
        if (!$decoded instanceof \stdClass || !isset($decoded->events) || !is_array($decoded->events)
            || count($decoded->events)!==count($batch['events'])
            || !array_all($decoded->events,static fn($item): bool => $item instanceof \stdClass)) {
            throw new \RuntimeException('Corrupt spool batch event count.');
        }
        return $batch;
    }

    private function atomicWrite(string $path,string $bytes): void
    {
        if (file_exists($path) || is_link($path)) throw new \RuntimeException('Spool ID collision.');
        $temp=dirname($path).'/.tmp-'.bin2hex(random_bytes(16));
        $oldUmask=umask(0077);
        try { $stream=@fopen($temp,'xb'); } finally { umask($oldUmask); }
        if (!$stream) throw new \RuntimeException('Cannot create spool file.');
        try {
            @chmod($temp,0600);
            $written=0;$length=strlen($bytes);
            while ($written<$length) {
                $n=@fwrite($stream,substr($bytes,$written));
                if ($n===false || $n===0) throw new \RuntimeException('Spool write failed.');
                $written+=$n;
            }
            if (!fflush($stream) || !fsync($stream)) throw new \RuntimeException('Spool fsync failed.');
        } catch (\Throwable $e) {
            fclose($stream); @unlink($temp); throw $e;
        }
        fclose($stream);
        if (!@rename($temp,$path)) { @unlink($temp); throw new \RuntimeException('Cannot commit spool file.'); }
    }
}
