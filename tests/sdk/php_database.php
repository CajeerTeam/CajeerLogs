<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
foreach (['DeliveryException','CajeerLogsClient','DurableBatchDelivery','Logger','MonologHandler'] as $class) {
    require dirname(__DIR__,2).'/packages/php-sdk/src/'.$class.'.php';
}
use Cajeer\Logs\Sdk\{CajeerLogsClient,DurableBatchDelivery,Logger,MonologHandler};

$client=new CajeerLogsClient(getenv('CL_TEST_URL'),getenv('CL_TEST_TOKEN'),signed:true);
(new Logger($client,'app'))->info('sdk psr3 real postgres event',['job_id'=>42]);
$logger=new \Monolog\Logger('test');
$logger->pushHandler(new MonologHandler($client,'app'));
$logger->warning('sdk monolog real postgres event',['job_id'=>43]);

$spool=sys_get_temp_dir().'/cajeer-php-sdk-pg-'.bin2hex(random_bytes(12));
try {
    $delivery=new DurableBatchDelivery($client,$spool,batchSize:2);
    $psr=new Logger($client,'app',delivery:$delivery);
    $monolog=new \Monolog\Logger('database-integration');
    $handler=new MonologHandler($client,'app',delivery:$delivery);
    $monolog->pushHandler($handler);
    $psr->info('sdk buffered psr3 postgres event');
    if ($delivery->pendingEvents()!==1) throw new RuntimeException('Buffered PSR-3 event not persisted');
    $monolog->warning('sdk buffered monolog postgres event',['job_id'=>44]);
    if ($delivery->pendingEvents()!==0) throw new RuntimeException('Buffered PostgreSQL batch not committed');
    $monolog->info('sdk buffered monolog close event');
    $handler->close();
    if ($delivery->pendingEvents()!==0) throw new RuntimeException('Monolog close did not flush to PostgreSQL');
} finally {
    foreach (['ready','batches'] as $dir) {
        foreach (glob($spool.'/'.$dir.'/*') ?: [] as $file) unlink($file);
        if (is_dir($spool.'/'.$dir)) rmdir($spool.'/'.$dir);
    }
    foreach (['.state.lock','.flush.lock'] as $name) if (is_file($spool.'/'.$name)) unlink($spool.'/'.$name);
    if (is_dir($spool)) rmdir($spool);
}
echo "PASS PSR-3 and Monolog 3 real PHP/PostgreSQL synchronous and buffered integration\n";
