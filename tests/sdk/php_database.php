<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
foreach (['DeliveryException','CajeerLogsClient','Logger','MonologHandler'] as $class) { require dirname(__DIR__,2).'/packages/php-sdk/src/'.$class.'.php'; }
use Cajeer\Logs\Sdk\{CajeerLogsClient,Logger,MonologHandler};
$client=new CajeerLogsClient(getenv('CL_TEST_URL'),getenv('CL_TEST_TOKEN'),signed:true);
(new Logger($client,'app'))->info('sdk psr3 real postgres event',['job_id'=>42]);
$logger=new \Monolog\Logger('test');$logger->pushHandler(new MonologHandler($client,'app'));
$logger->warning('sdk monolog real postgres event',['job_id'=>43]);
echo "PASS PSR-3 and Monolog real PHP/PostgreSQL integration\n";
