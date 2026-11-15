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
foreach (['php-denied'=>401,'php-redirect'=>307,'php-malformed'=>201] as $key=>$status) {
    try { $c->ingest($event,$key);throw new RuntimeException('Expected delivery failure'); }
    catch (DeliveryException $e) { if ($e->status!==$status) { throw $e; } }
}
foreach ([['timestamp'=>'2026-02-30T00:00:00Z'],['message'=>123],['context'=>null],['tags'=>[null]],['source'=>'APP'],['message'=>"bad\x1b"]] as $bad) {
    try {$c->ingest(array_replace($event,$bad),'php-invalid');throw new RuntimeException('Invalid event accepted');}
    catch (\InvalidArgumentException $e) {}
}
echo "PHP SDK wire tests passed\n";
