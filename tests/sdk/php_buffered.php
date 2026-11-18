<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach (['DeliveryException','CajeerLogsClient','DurableBatchDelivery'] as $name) require $root.'/packages/php-sdk/src/'.$name.'.php';
use Cajeer\Logs\Sdk\{CajeerLogsClient,DurableBatchDelivery,DeliveryException};
[$script,$mode,$url,$token,$directory]=$argv;
$client=new CajeerLogsClient($url,$token,signed:true,timeout:1.0,attempts:$mode==='lost-restart'?1:2);
$batchSize = $mode==='byte-boundary' ? 3 : ($mode==='capacity'?2:($mode==='permanent'?1:($mode==='lost' || $mode==='lost-restart'?2:($mode==='concurrent'?100:3))));
$delivery=new DurableBatchDelivery($client,$directory,$batchSize,$mode==='capacity'?2:10000,
    $mode==='byte-boundary'?65536:1048576,in_array($mode,['outage','capacity'],true));
$event=static fn(string $message,array $context=[]): array => ['timestamp'=>'2026-11-18T00:00:00Z','source'=>'sdk','level'=>'info','message'=>$message,'context'=>(object)$context];
$check=static function(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); };
switch($mode) {
    case 'first':
        $delivery->enqueue($event('first'));
        $delivery->enqueue($event('second'));
        $check($delivery->pendingEvents()===2,'first should remain durable');
        break;
    case 'resume':
        $check($delivery->pendingEvents()===2,'prior events not durable');
        $delivery->enqueue($event('third'));
        $check($delivery->pendingEvents()===0,'resumed batch not confirmed');
        break;
    case 'lost':
        $delivery->enqueue($event('lost-one'));
        $delivery->enqueue($event('lost-two'));
        $check($delivery->pendingEvents()===0,'lost response not recovered');
        break;
    case 'lost-restart':
        try {
            $delivery->enqueue($event('lost-restart-one'));
            $delivery->enqueue($event('lost-restart-two'));
            throw new RuntimeException('lost response was accepted without HTTP receipt');
        } catch (DeliveryException $e) { $check($e->status===null, 'Unexpected lost-response status'); }
        $check($delivery->pendingEvents()===2, 'lost response discarded unconfirmed batch');
        break;
    case 'lost-recover':
        $check($delivery->pendingEvents()===2, 'lost response was not spooled');
        $check($delivery->flush()===2 && $delivery->pendingEvents()===0,'lost receipt not replayed');
        break;
    case 'concurrent':
        $prefix=$argv[5] ?? 'default';
        for ($i=0;$i<30;$i++) $delivery->enqueue($event('parallel-'.$prefix.'-'.$i));
        break;
    case 'concurrent-flush':
        $check($delivery->pendingEvents()===60,'concurrent writers lost spool files');
        $check($delivery->flush()===60 && $delivery->pendingEvents()===0,'concurrent flush failed');
        break;
    case 'outage':
        $delivery->enqueue($event('outage-one'));
        $delivery->enqueue($event('outage-two'));
        $delivery->enqueue($event('outage-three'));
        $check($delivery->pendingEvents()===3 && $delivery->lastFailure() instanceof DeliveryException && $delivery->lastFailure()->status===503,'outage should retain batch and report failure');
        // Simulate the exact crash window after durable batch commit but before
        // the original ready file was unlinked. Recovery must not send it twice.
        $batchFiles=glob($directory.'/batches/*.json');
        $check(is_array($batchFiles) && count($batchFiles)===1,'outage missing immutable batch');
        $batch=json_decode(file_get_contents($batchFiles[0]),true,16,JSON_THROW_ON_ERROR);
        $sourceId=$batch['events'][0];
        $events=json_decode($batch['body'],true,32,JSON_THROW_ON_ERROR)['events'];
        $ready=$directory.'/ready/'.$sourceId.'.json';
        file_put_contents($ready,json_encode($events[0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        chmod($ready,0600);
        $check($delivery->pendingEvents()===3 && !file_exists($ready),'post-commit crash reference not reconciled');
        break;
    case 'outage-recover':
        $check($delivery->pendingEvents()===3,'outage lost events across restart');
        $check($delivery->flush()===3 && $delivery->pendingEvents()===0,'outage not recovered');
        break;
    case 'permanent':
        try { $delivery->enqueue($event('denied-event'));throw new RuntimeException('401 accepted'); }
        catch (DeliveryException $e) { $check($e->status===401,'unexpected denial status'); }
        $check($delivery->pendingEvents()===1,'permanent failure dropped event');
        break;
    case 'capacity':
        $delivery->enqueue($event('outage-full-one'));
        $delivery->enqueue($event('outage-full-two'));
        try { $delivery->enqueue($event('outage-over-capacity'));throw new RuntimeException('overflow not enforced'); }
        catch (OverflowException) {}
        $check($delivery->pendingEvents()===2,'spool accounting incorrect');
        break;
    case 'byte-boundary':
        $delivery->enqueue($event('size-a',['blob'=>str_repeat('x',32000)]));
        $delivery->enqueue($event('size-b',['blob'=>str_repeat('y',32000)]));
        $delivery->enqueue($event('size-c',['blob'=>str_repeat('z',32000)]));
        $check($delivery->pendingEvents()===0,'byte boundary batching failed');
        break;
    case 'invalid':
        try { $delivery->enqueue($event('not-valid')+['badfield'=>123]);throw new RuntimeException('invalid event accepted'); }
        catch (InvalidArgumentException) {}
        $check($delivery->pendingEvents()===0,'invalid event changed spool');
        break;
    default: throw new RuntimeException('Bad test mode');
}
echo "PHP buffered $mode passed\n";
