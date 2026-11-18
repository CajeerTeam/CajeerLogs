<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueuedJob,RedisQueueDriver,RabbitMqQueueDriver};
use Cajeer\Logs\Log\{IngestValidator,IngestEvent};
use Cajeer\Logs\Http\{Request,ErrorHandler,HttpException};
use Cajeer\Logs\Kernel\Application;
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();
$count=0;
function check(bool $ok,string $label):void {global $count;if (!$ok) throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
function rejects(callable $fn,string $class):bool {try{$fn();}catch(Throwable $e){return $e instanceof $class;}return false;}
$body='{"timestamp":"2026-11-14T00:00:00Z","source":"app","level":"ERROR","message":"hello","context":{"password":"secret","nested":{"key":"value"}},"tags":["x","x"]}';
$events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest/async',['Content-Type'=>'application/json'],[],$body));
$payload=PostgresQueueDriver::encodeEvents($events);
$decoded=PostgresQueueDriver::decodeEvents(json_decode(json_encode($payload,JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR));
check($decoded[0] instanceof IngestEvent && $decoded[0]->level==='error' && $decoded[0]->context->password==='[redacted]','normalized redacted queued event round trip');
check($decoded[0]->context->nested->key==='value' && $decoded[0]->tags===['x'] && $decoded[0]->service===null,'queued metadata fidelity');
check(!str_contains(json_encode($payload,JSON_THROW_ON_ERROR),'"secret"'),'no raw secret in payload');
$empty=new IngestEvent('app','info','empty',new stdClass(),'2026-11-14T00:00:00Z');
check(PostgresQueueDriver::decodeEvents(json_decode(json_encode(PostgresQueueDriver::encodeEvents([$empty]),JSON_THROW_ON_ERROR)))[0]->context instanceof stdClass,'empty JSON object survives queue round trip');
check(PostgresQueueDriver::backoff(1)===5&&PostgresQueueDriver::backoff(2)===10&&PostgresQueueDriver::backoff(8)===640&&PostgresQueueDriver::backoff(99)===3600,'bounded exponential backoff');
check(rejects(fn()=>(new RedisQueueDriver())->push(new QueuedJob('x')),LogicException::class),'Redis unsupported fails closed');
check(rejects(fn()=>(new RabbitMqQueueDriver())->push(new QueuedJob('x')),LogicException::class),'RabbitMQ unsupported fails closed');
$err=ErrorHandler::response(new HttpException(503,'queue_full','internal secret',['Retry-After'=>'5']),'request');
check($err->status===503&&$err->header('Retry-After')==='5'&&!str_contains($err->body,'internal secret'),'backpressure safe response and retry header');
$app->config()->set('app.env','test');$app->config()->set('app.url','http://localhost:8080');$headers=['Authorization'=>'Bearer cl_'.str_repeat('a',64),'Content-Type'=>'application/json'];
check($app->handle(new Request('POST','/api/v1/ingest/async',$headers,[],$body))->status===422,'async requires idempotency key before DB');
check($app->handle(new Request('POST','/api/v1/ingest/async',[],[],$body))->status===401,'async requires bearer');
check($app->handle(new Request('POST','/api/v1/ingest/async',$headers+['Idempotency-Key'=>'bad key'],[],$body))->status===422,'invalid async key rejected');
check($app->handle(new Request('GET','/api/v1/ingest/jobs/1'))->status===401,'job status requires bearer');
check($app->handle(new Request('GET','/api/v1/ingest/jobs/9223372036854775808',$headers))->status===422,'job ID bigint bounds');
check($app->handle(new Request('GET','/api/v1/ingest/async'))->status===405,'async method restriction');
check($app->handle(new Request('DELETE','/api/v1/ingest/jobs/1',$headers))->status===405,'job status read only');
$app->config()->set('app.env','production');$app->config()->set('app.url','https://logs.example.test');
check($app->handle(new Request('POST','/api/v1/ingest/async',$headers+['Idempotency-Key'=>'test'],[],$body))->status===403,'production async requires TLS');
check($app->handle(new Request('GET','/api/v1/ingest/jobs/1',$headers))->status===403,'production job status requires TLS');
putenv('QUEUE_MAX_JOBS=0');check(rejects(fn()=>(new Application($root))->boot(),RuntimeException::class),'invalid queue capacity rejected');putenv('QUEUE_MAX_JOBS');
check(Cajeer\Logs\Scheduler\Scheduler::TASKS===['retention'=>60,'auth_cleanup'=>300,'queue_cleanup'=>3600,'webhook_cleanup'=>3600,'source_metrics_cleanup'=>300],'bounded fixed scheduler intervals');
echo "Queue lifecycle: $count checks passed\n";
