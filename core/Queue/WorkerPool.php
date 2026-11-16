<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

/** Supervisor starts independent PHP processes (never forks a live PDO connection). */
final class WorkerPool
{
    private bool $stop=false;
    public function __construct(private readonly WorkerPolicy $policy,private readonly array $command) {}
    public function run(int $size): int
    {
        if ($size<1||$size>32||!function_exists('pcntl_signal')||!function_exists('posix_getppid')||!function_exists('proc_open')) throw new \RuntimeException('Queue pool requires 1–32 processes, pcntl, posix and proc_open.');
        $oldAsync=pcntl_async_signals(true);$oldTerm=pcntl_signal_get_handler(SIGTERM);$oldInt=pcntl_signal_get_handler(SIGINT);
        pcntl_signal(SIGTERM,fn()=> $this->stop=true);pcntl_signal(SIGINT,fn()=> $this->stop=true);
        $children=[];$restart=[];$failures=[];$pool=bin2hex(random_bytes(16));$result=0;
        $env=getenv();$env['CAJEER_QUEUE_POOL_ID']=$pool;$env['CAJEER_QUEUE_POOL_PARENT']=(string)getmypid();
        try {
            while (!$this->stop) {
                $now=hrtime(true);
                for ($slot=0;$slot<$size && !$this->stop;$slot++) {
                    if (isset($children[$slot])) {
                        $status=proc_get_status($children[$slot]['process']);
                        if ($status['running']) continue;
                        $child=$children[$slot];unset($children[$slot]);proc_close($child['process']);
                        $lifetime=($now-$child['start'])/1e9;
                        $failures[$slot]=$status['exitcode']===0 && $lifetime>=1 ? 0 : ($lifetime>=60?1:($failures[$slot]??0)+1);
                        $delay=min(30,2**min(5,$failures[$slot]));
                        $restart[$slot]=$now+$delay*1_000_000_000;
                        fwrite(STDERR,"Queue worker slot $slot exited; replacement in {$delay}s.\n");
                    }
                    if (($restart[$slot]??0)>$now) continue;
                    $process=@proc_open($this->command,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,null,$env,['bypass_shell'=>true]);
                    if (!is_resource($process)) { $restart[$slot]=$now+5_000_000_000;continue; }
                    $children[$slot]=['process'=>$process,'start'=>$now];
                }
                usleep(100000);
            }
        } finally {
            // Stop claiming first. A worker finishing its current transaction exits normally.
            foreach ($children as $child) proc_terminate($child['process'],SIGTERM);
            $deadline=hrtime(true)+$this->policy->get('shutdown_grace_seconds')*1_000_000_000;
            while ($children!==[] && hrtime(true)<$deadline) {
                foreach ($children as $slot=>$child) if (!proc_get_status($child['process'])['running']) {proc_close($child['process']);unset($children[$slot]);}
                if ($children!==[]) usleep(100000);
            }
            foreach ($children as $child) {proc_terminate($child['process'],SIGKILL);proc_close($child['process']);$result=1;}
            pcntl_signal(SIGTERM,$oldTerm);pcntl_signal(SIGINT,$oldInt);pcntl_async_signals($oldAsync);
        }
        return $result;
    }
}
