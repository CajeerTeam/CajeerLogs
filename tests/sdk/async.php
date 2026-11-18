<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach (['DeliveryException','JobStatusException','JobFailedException','JobPendingException','CajeerLogsClient'] as $name) require "$root/packages/php-sdk/src/$name.php";
use Cajeer\Logs\Sdk\{CajeerLogsClient,DeliveryException,JobFailedException,JobPendingException};
$c=new CajeerLogsClient($argv[1],$argv[2],signed:true,timeout:2,attempts:2);
$event=['timestamp'=>'2026-11-17T12:00:00Z','source'=>'app','level'=>'info','message'=>'async'];
foreach (['php-async-lost','php-async-retry','php-async-replay'] as $key) {
    $r=$c->ingestAsync([$event,$event],$key);
    if ($r['received']!==2 || $c->waitForJob($r,2,0.05)['result']['stored']!==2) throw new RuntimeException('Missing async commit');
    if ($key==='php-async-replay') {
        $rr=$c->ingestAsync([$event,$event],$key);
        if (!$rr['replayed'] || $rr['job_id']!==$r['job_id']) throw new RuntimeException('Bad replay');
    }
}
foreach (['dead','cancelled'] as $status) {
    $r=$c->ingestAsync($event,'php-async-'.$status);
    try { $c->waitForJob($r,0.3,0.05); throw new RuntimeException('False completion'); }
    catch (JobFailedException $e) { if ($e->jobStatus!==$status) throw $e; }
}
$r=$c->ingestAsync($event,'php-async-pending');
try { $c->waitForJob($r,0.15,0.05); throw new RuntimeException('False completion'); }
catch (JobPendingException $e) { if ($e->jobId!==$r['job_id']) throw $e; }
$r=$c->ingestAsync([$event,$event],'php-async-invalid');
try { $c->waitForJob($r,1,0.05); throw new RuntimeException('Accepted bad result'); }
catch (UnexpectedValueException $e) {}
foreach (['redirect','401','badreceipt'] as $kind) {
    try { $c->ingestAsync($event,'php-async-'.$kind); throw new RuntimeException('False acceptance'); }
    catch (DeliveryException $e) { if (($kind==='redirect' && $e->status!==307) || ($kind==='401' && $e->status!==401) || ($kind==='badreceipt' && $e->status!==202)) throw $e; }
}
echo "PHP async recovery HTTP tests passed\n";
