<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{IngestValidator,StrictEventJson,IngestBatch,IngestEvent,LogRepository};
use Cajeer\Logs\Http\{Request,HttpException,ErrorHandler};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function parse(string $raw):array{return (new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$raw));}
function bad(callable $f,int $status,?string $reason=null):HttpException{try{$f();}catch(HttpException $e){if($e->status===$status&&($reason===null||($e->details['reason']??null)===$reason))return $e;throw new RuntimeException('Unexpected HTTP validation error '.$e->status.' '.$e->getMessage());}throw new RuntimeException('Unexpected acceptance');}
function json(mixed $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
$raw='{"timestamp":"2026-01-01T00:00:00Z","source":"app","level":"info","message":"ok"}';
$e=json_decode($raw);$withContext=fn(string $c)=>substr($raw,0,-1).',"context":'.$c.'}';
foreach (['{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"nested":[{"x":1,"x":2}]}','{"\\ud83d\\ude00":1,"😀":2}','{"0":1,"\\u0030":2}'] as $ctx) check(bad(fn()=>parse($withContext($ctx)),400,'duplicate_key')->details['field']==='context','duplicate object key rejected after decoding');
check(bad(fn()=>parse(substr($raw,0,-1).',"message":"different"}'),400,'duplicate_key')->status===400,'duplicate event field rejected');
check(bad(fn()=>parse('{"events":['.$raw.'],"events":['.$raw.']}'),400,'duplicate_key')->status===400,'duplicate batch field rejected');
$dup=bad(fn()=>parse('{"events":['.$raw.','.$withContext('{"secret_key":1,"secret_key":2}').']}'),400,'duplicate_key');
check($dup->details['event_index']===1&&$dup->details['field']==='context'&&!str_contains($dup->getMessage(),'secret_key'),'nested duplicate reports safe batch position');
foreach (['', '{','[','{"a":}', '{"a":1,}','{"a":01}','{"a":1e}','{"a":"\\uD800"}','{"a":"\\x"}','{"a":true false}','{"a":1}x', "{\"a\":\"\xff\"}",'{"a":"unterminated\\'] as $rawBad) {try{StrictEventJson::decode($rawBad);throw new RuntimeException('accepted malformed');}catch(HttpException $ex){check($ex->status===400,'malformed JSON rejected');}}
check(parse($withContext(str_replace('😀','\\ud83d\\ude00',json((object)['a'=>(object)[],'b'=>[],'escaped'=>'\\"','smile'=>'😀']))))[0]->context->smile==='😀','valid escaped Unicode and object/array shapes');
foreach (['source','message','level','timestamp','service','host','trace_id','request_id'] as $field) { $v=clone $e;$v->$field='MARKER'; $text=str_replace('"MARKER"','123456789012345678901234',json($v)); check(bad(fn()=>parse($text),422)->details['field']===$field,'large integer cannot masquerade as '.$field); }
$numbers=parse($withContext('{"big":123456789012345678901234,"int":9223372036854775807,"decimal":0.1234567890123456789,"small":1e-999,"float":1.25,"large":1e300,"nested":[1.234567890123456789]}'))[0]->context;
check($numbers->big==='123456789012345678901234'&&$numbers->int===PHP_INT_MAX,'large integer precision preserved');
check($numbers->decimal==='0.1234567890123456789'&&$numbers->small==='1e-999'&&$numbers->nested[0]==='1.234567890123456789','decimal precision and underflow preserved');
check($numbers->float===1.25&&$numbers->large===1e300,'ordinary finite numbers remain numeric');
check(bad(fn()=>parse($withContext('{"x":1e999}')),422,'invalid_number')->details['field']==='context','overflow rejected');
foreach (["\0","\x1b","\x7f","\x01"] as $char) { $v=clone $e;$v->message='ok'.$char;check(bad(fn()=>parse(json($v)),422,'invalid_character')->details['field']==='message','unsupported control rejected'); }
$v=clone $e;$v->message="line\n\tcontinued\r";check(parse(json($v))[0]->message===$v->message,'normal log whitespace preserved');
foreach ([str_repeat('x',257),"bad\tkey", "bad\0key"] as $key) check(bad(fn()=>parse($withContext(json((object)[$key=>'x']))),422,'invalid_key')->status===422,'invalid context key rejected');
check(bad(fn()=>parse($withContext(json(['x'=>array_fill(0,2049,0)]))),422,'structure_limit')->status===422,'container item limit');
check(bad(fn()=>parse($withContext(json(['x'=>array_fill(0,5,array_fill(0,1800,0))]))),422,'structure_limit')->status===422,'per-event node limit');
$nested=0;for($i=0;$i<25;$i++)$nested=[$nested];check(bad(fn()=>parse($withContext(json(['x'=>$nested]))),422,'structure_limit')->status===422,'event depth limit');
check(bad(fn()=>parse(substr($raw,0,-1).str_repeat(' ',65536).'}'),413,'event_size')->status===413,'raw event byte limit including whitespace');
check(bad(fn()=>parse('{"events":['.implode(',',array_fill(0,101,$raw)).']}'),422,'batch_size')->status===422,'early batch count limit');
$invalid=clone $e;$invalid->timestamp='2026-02-30T00:00:00Z';$error=bad(fn()=>parse(json(['events'=>[$e,$invalid]])),422);
check($error->details===['event_index'=>1,'reason'=>'invalid_field','field'=>'timestamp'],'safe structured field error');
$response=ErrorHandler::response($error,'req-1');$data=json_decode($response->body,true);check($response->status===422&&$data['details']===$error->details&&$data['request_id']==='req-1','structured error serialized');
$time=strtotime('2026-01-01T00:00:00Z');$events=parse($raw);
IngestValidator::checkTime($events,1,1,$time+1);IngestValidator::checkTime($events,1,1,$time-1);check(true,'inclusive timestamp window boundaries');
check(bad(fn()=>IngestValidator::checkTime($events,1,0,$time+2),422,'timestamp_window')->details['field']==='timestamp','old timestamp denied');
check(bad(fn()=>IngestValidator::checkTime($events,0,1,$time-2),422,'timestamp_window')->details['event_index']===0,'future timestamp denied');
IngestValidator::checkTime($events,0,0,$time+1000000);check(true,'disabled timestamp window permits historical import');
$legacy=new IngestEvent('app','info',"legacy\x1b",(object)['long'=>array_fill(0,2049,1)],$events[0]->timestamp);$source='22222222-2222-4222-8222-222222222222';
$body=json(['events'=>[$legacy],'sources'=>(object)['app'=>$source]]);[$decoded,$map,$version]=IngestBatch::decodeJob($body);check($version===1&&$decoded[0]->message===$legacy->message&&count($decoded[0]->context->long)===2049,'legacy pending job retains previous validation rules');
[$ids,$sql]=LogRepository::encodeBatch($decoded,$map,$version);check(count($ids)===1&&str_contains($sql,'legacy'),'legacy job can reach actual SQL encoder');
check(bad(fn()=>IngestBatch::encodeJob([$legacy],$map),422)->status===422,'new job uses stricter validation');
$body=IngestBatch::encodeJob($events,$map);check(json_decode($body)->validation_version===2&&IngestBatch::decodeJob($body)[2]===2,'versioned durable job');
$unknown=json_decode($body);$unknown->validation_version=3;check(bad(fn()=>IngestBatch::decodeJob(json($unknown)),422)->status===422,'unknown durable version rejected');
// Seeded bounded grammar fuzz: valid PHP JSON agrees, every truncated prefix of an object fails safely.
mt_srand(230);for($i=0;$i<200;$i++){ $v=(object)['x'=>[mt_rand(-1000,1000),true,null,chr(mt_rand(32,126)).'\\"😀', (object)['y'=>mt_rand(0,99)]]];$j=json($v);if(json(StrictEventJson::decode($j))!==$j)throw new RuntimeException('Differential JSON mismatch'); }
check(true,'200 deterministic valid JSON grammar cases');
$j=json((object)['a'=>['x','😀',(object)['q'=>'\\"']],'b'=>true]);for($i=0;$i<strlen($j);$i++){try{StrictEventJson::decode(substr($j,0,$i));throw new RuntimeException('Truncated JSON accepted');}catch(HttpException $ex){if($ex->status!==400)throw $ex;}}check(true,'every truncated object prefix rejected safely');
echo "Event validation: $n checks passed\n";
