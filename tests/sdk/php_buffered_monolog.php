<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
foreach (['DeliveryException','CajeerLogsClient','DurableBatchDelivery','Logger','MonologHandler'] as $name) require $root.'/packages/php-sdk/src/'.$name.'.php';
use Cajeer\Logs\Sdk\{CajeerLogsClient,DurableBatchDelivery,Logger,MonologHandler};
[$script,$url,$token,$directory]=$argv;
$client=new CajeerLogsClient($url,$token,signed:true,timeout:2,attempts:2);
$buffer=new DurableBatchDelivery($client,$directory,3);
$psr=new Logger($client,'app','api',$buffer);
$psr->info('User {user} logged in',['user'=>42,'details'=>['foo'=>true]]);
$psr->error('Exception {code}',['code'=>500,'exception'=>new RuntimeException('failure')]);
if ($buffer->pendingEvents()!==2) throw new RuntimeException('PSR-3 adapter flushed too early');
$mono=new \Monolog\Logger('app-channel');
$handler=new MonologHandler($client,'app','worker',delivery:$buffer);
$mono->pushHandler($handler);
$mono->warning('Monolog logged', ['job_id'=>42]);
if ($buffer->pendingEvents()!==0) throw new RuntimeException('Monolog shared batch did not flush');
$mono->info('flush on close');
$handler->close();
if ($buffer->pendingEvents()!==0) throw new RuntimeException('Monolog close did not flush');
if ($psr->flush()!==0) throw new RuntimeException('PSR flush incorrectly resent events');
try { $psr->log('invalid','bad');throw new RuntimeException('invalid PSR-3 level accepted'); }
catch (\Psr\Log\InvalidArgumentException) {}
echo "PSR-3 and real Monolog 3 buffered delivery passed\n";
