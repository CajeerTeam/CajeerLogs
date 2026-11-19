<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/packages/php-sdk/src/DeliveryException.php';
require dirname(__DIR__,2).'/packages/php-sdk/src/CajeerLogsClient.php';
use Cajeer\Logs\Sdk\{CajeerLogsClient,DeliveryException};
$c=new CajeerLogsClient($argv[1],$argv[2],true,2,2);
$event=['timestamp'=>'2026-01-01T00:00:00Z','source'=>'app','level'=>'info','message'=>'Привет','context'=>[]];
$r=$c->ingest([$event,$event],'php-retry');
if ($r['stored']!==2) { throw new RuntimeException('Wrong receipt'); }
if (!$c->ingest($event,'php-replay')['replayed']) { throw new RuntimeException('Wrong replay'); }
if (!$c->sendBody(json_encode($event,JSON_THROW_ON_ERROR),'php-lost')['replayed']) throw new RuntimeException('Lost response recovery');
try { $c->ingest([$event,$event],'php-duplicate'); throw new RuntimeException('Duplicate receipt accepted'); } catch (DeliveryException $e) {}
foreach (['php-denied'=>401,'php-redirect'=>307,'php-malformed'=>201] as $key=>$status) {
    try { $c->ingest($event,$key);throw new RuntimeException('Expected delivery failure'); }
    catch (DeliveryException $e) { if ($e->status!==$status || ($status===401 && ($e->apiError!=='unauthorized' || $e->requestId!==str_repeat('a',32)))) { throw $e; } }
}
foreach ([['timestamp'=>'2026-02-30T00:00:00Z'],['message'=>123],['context'=>null],['tags'=>[null]],['source'=>'APP'],['message'=>"bad\x1b"]] as $bad) {
    try {$c->ingest(array_replace($event,$bad),'php-invalid');throw new RuntimeException('Invalid event accepted');}
    catch (\InvalidArgumentException $e) {}
}
$v2=new CajeerLogsClient($argv[1],$argv[2],signingKeyId:'33333333-3333-4333-8333-333333333333',signingSecret:'sk_'.str_repeat('b',64));
if($v2->ingest($event,'php-v2')['stored']!==1) throw new RuntimeException('v2 failed');
echo "PHP SDK wire tests passed\n";
