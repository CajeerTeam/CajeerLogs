<?php
declare(strict_types=1);

use Cajeer\Logs\Http\{HttpException,Request};
use Cajeer\Logs\Log\IngestValidator;
use Cajeer\Logs\Security\SignedPayload;

$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot(); $app->config()->set('app.env','test');
$count=0;
function check(bool $ok,string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$label); } ++$count; echo "PASS $label\n"; }
function rejects(callable $fn,int $status): bool { try { $fn(); } catch (HttpException $e) { return $e->status===$status; } return false; }
function req(string $raw,array $headers=[]): Request { return new Request('POST','/api/v1/ingest',$headers+['Content-Type'=>'application/json'],[],$raw); }
function encode(mixed $data):string { return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
$validator=new IngestValidator();
$event=['timestamp'=>'2026-03-04T10:11:12.123456+03:00','source'=>'app','level'=>'INFO','message'=>'Hello password=secret-value Bearer abcd1234','context'=>(object)['api_key'=>'secret-key','nested'=>[(object)['accessToken'=>'abc'],(object)[]],'empty'=>(object)[],'list'=>[],'ok'=>true,'big'=>123],'tags'=>['a','a','b'],'service'=>'backend','host'=>'host','trace_id'=>'trace','request_id'=>'request'];
$parsed=$validator->parse(req(encode($event)))[0];
check($parsed->timestamp==='2026-03-04T07:11:12.123456Z'&&$parsed->level==='info','timestamp UTC and normalized level');
check($parsed->source==='app'&&$parsed->service==='backend'&&$parsed->host==='host'&&$parsed->traceId==='trace'&&$parsed->requestId==='request','all metadata mapped');
check($parsed->context->api_key==='[redacted]'&&$parsed->context->nested[0]->accessToken==='[redacted]'&&$parsed->context->ok===true,'structured secret redaction');
check((new \Cajeer\Logs\Log\Redactor())->redact(['dbPassword'=>'hidden'])['dbPassword']==='[redacted]','camelCase sensitive key');
check(encode($parsed->context->empty)==='{}'&&encode($parsed->context->list)==='[]'&&encode($parsed->context->nested[1])==='{}','JSON object and array shape preserved');
check(!str_contains($parsed->message,'secret-value')&&!str_contains($parsed->message,'abcd1234')&&$parsed->tags===['a','b'],'text masking and tag deduplication');
check(count($validator->parse(req(encode(['events'=>[$event,$event]]))))===2,'batch parse');
$minimal=['timestamp'=>'2026-01-01T00:00:00Z','source'=>'app','level'=>'debug','message'=>'hello'];
$empty=$validator->parse(req(encode($minimal)))[0];
check($empty->timestamp==='2026-01-01T00:00:00.000000Z'&&encode($empty->context)==='{}'&&$empty->tags===[]&&$empty->host===null,'optional defaults');
foreach (['debug','info','notice','warning','error','critical','alert','emergency'] as $level) { check($validator->parse(req(encode(array_replace($minimal,['level'=>$level]))))[0]->level===$level,'PSR-3 '.$level); }
foreach (['timestamp','source','level','message'] as $field) { $bad=$minimal; unset($bad[$field]); check(rejects(fn()=>$validator->parse(req(encode($bad))),422),'missing '.$field); }
foreach (['2026-02-29T00:00:00Z','2026-01-01T24:00:00Z','2026-01-01T00:00:00','2026-01-01T00:00:00.1234567Z','2026-01-01T00:00:00+24:00','0000-01-01T00:00:00Z','0001-01-01T00:00:00+01:00','9999-12-31T23:59:59-01:00','2026-01-01T00:00:60Z'] as $time) { check(rejects(fn()=>$validator->parse(req(encode(array_replace($minimal,['timestamp'=>$time])))),422),'invalid timestamp '.$time); }
foreach ([['source'=>'../app'],['source'=>'APP'],['source'=>12],['level'=>'fatal'],['message'=>''],['message'=>"nul\0"],['message'=>str_repeat('x',16385)],['context'=>[]],['context'=>null],['context'=>(object)['secret'=>str_repeat('x',32769)]],['tags'=>['x',null]],['tags'=>array_fill(0,33,'a')],['trace_id'=>str_repeat('a',129)],['project_id'=>'injected'],['service'=>42],['host'=>str_repeat('password=x ',23)]] as $bad) {
    // The last item grows beyond its storage limit after masking.
    check(rejects(fn()=>$validator->parse(req(encode(array_replace($minimal,$bad)))),422),'invalid field '.array_key_first($bad));
}
foreach (['[]','null','{"events":[]}','{"events":{}}','{"events":[1]}','{"events":[],"source":"app"}'] as $bad) { check(rejects(fn()=>$validator->parse(req($bad)),422),'invalid payload '.$bad); }
check(rejects(fn()=>$validator->parse(req('{')),400),'malformed JSON');
check(rejects(fn()=>$validator->parse(req(str_repeat(' ',1048577))),413),'ingest body hard limit');
check(rejects(fn()=>$validator->parse(req(encode($minimal),['Content-Type'=>'text/plain'])),415),'content type');
check(rejects(fn()=>$validator->parse(req(encode($minimal),['Content-Encoding'=>'gzip'])),415),'content encoding');
check(rejects(fn()=>$validator->parse(req(encode(['events'=>array_fill(0,101,$minimal)]))),422),'batch limit');
check(rejects(fn()=>$validator->parse(req(encode(['events'=>[$minimal,['message'=>'invalid']]]))),422),'all events validated');
check(rejects(fn()=>$validator->parse(req(encode(array_replace($minimal,['context'=>(object)['x'=>str_repeat('x',65536)]])))),413),'event byte limit');
check(rejects(fn()=>$validator->parse(req('{"timestamp":"2026-01-01T00:00:00Z","source":"app","level":"info","message":"hi","context":{"x":1e999}}')),422),'nonfinite JSON rejected');
$big=$validator->parse(req('{"timestamp":"2026-01-01T00:00:00Z","source":"app","level":"info","message":"hi","context":{"x":12345678901234567890123}}'))[0];
check($big->context->x==='12345678901234567890123','large integer preserved as string');
$secret='cl_'.str_repeat('a',64); $timestamp='1767225600'; $nonce=str_repeat('b',32); $key='request-1'; $raw=encode($minimal); $signer=new SignedPayload();
$signature=$signer->sign(SignedPayload::canonical(req($raw),$timestamp,$nonce,$key),$secret);
$headers=['X-Log-Timestamp'=>$timestamp,'X-Log-Nonce'=>$nonce,'X-Log-Signature'=>$signature];
check($signer->verify(req($raw,$headers),$secret,$key,true,(int)$timestamp)===$nonce,'valid canonical signature');
check($signer->verify(req($raw),$secret,null,false,(int)$timestamp)===null,'optional unsigned request');
check(rejects(fn()=>$signer->verify(req($raw),$secret,null,true,(int)$timestamp),401),'required signature');
check(rejects(fn()=>$signer->verify(req($raw,['X-Log-Nonce'=>$nonce]),$secret,$key,false,(int)$timestamp),401),'partial signature rejected');
check(rejects(fn()=>$signer->verify(req($raw.' ',$headers),$secret,$key,true,(int)$timestamp),401),'signed body tampering');
check(rejects(fn()=>$signer->verify(req($raw,$headers),$secret,'other-key',true,(int)$timestamp),401),'signed idempotency key tampering');
check(rejects(fn()=>$signer->verify(new Request('POST','/other',$headers,[],$raw),$secret,$key,true,(int)$timestamp),401),'signed path tampering');
check(rejects(fn()=>$signer->verify(req($raw,$headers),$secret,$key,true,(int)$timestamp+301),401),'stale signature');
check(rejects(fn()=>$signer->verify(req($raw,$headers),$secret,$key,true,(int)$timestamp-301),401),'future signature');
check(rejects(fn()=>$signer->verify(req($raw,$headers),'wrong',$key,true,(int)$timestamp),401),'wrong secret');
check($app->handle(req($raw))->status===401,'runtime requires bearer');
check($app->handle(req($raw,['Cookie'=>'cajeerlogs_session='.str_repeat('a',64)]))->status===403,'session credential not accepted');
check($app->handle(req($raw,['Authorization'=>'Bearer '.$secret,'Idempotency-Key'=>'bad key']))->status===422,'idempotency key validation');
$app->config()->set('database.default','unsupported');
check($app->handle(req($raw,['Authorization'=>'Bearer '.$secret]))->status===503,'database unavailable never accepted');
$app->config()->set('app.env','production');
check($app->handle(req($raw,['Authorization'=>'Bearer '.$secret,'X-Forwarded-Proto'=>'https']))->status===403,'production TLS required');
echo "Ingest runtime: $count checks passed\n";
