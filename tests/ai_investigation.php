<?php
declare(strict_types=1);
use Cajeer\Logs\AI\{Assistant,Investigation,OpenAIClient};
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function reject(callable $f):bool{try{$f();}catch(HttpException $e){return $e->status===422;}return false;}
$p='11111111-1111-4111-8111-111111111111';$a='22222222-2222-4222-8222-222222222222';$b='33333333-3333-4333-8333-333333333333';
$base=['project_id'=>$p,'question'=>'Find the failure','hours'=>24,'keyword'=>'timeout','level'=>'error','consent'=>true];
$r=Assistant::validate($base+['investigation'=>[]],1700000000);check($r['investigation']===[],'automatic investigation');
foreach([null,'trace',['event_id'=>'bad'],['trace_id'=>''],['request_id'=>str_repeat('x',129)],['trace_id'=>"a\n"],['trace_id'=>'a','request_id'=>'b'],['other'=>'a']] as $bad)check(reject(fn()=>Assistant::validate($base+['investigation'=>$bad])),'reject invalid selector');
[$sql,$args]=Investigation::seedStatement($r['query'],[],true);check(str_contains($sql,'LIMIT 9')&&$args['project_id']===$p&&$args['q']==='timeout'&&$args['level']==='error'&&str_contains($sql,'stack_trace')&&!str_contains($sql,'SELECT context'),'bounded seed text projection');
[$sql,$args]=Investigation::seedStatement($r['query'],['event_id'=>$a],false);check($args['anchor']===$a&&$args['project_id']===$p&&!isset($args['q'],$args['level'])&&!str_contains($sql,'message')&&!str_contains($sql,'context'),'anchor and metadata only');
[$sql,$args]=Investigation::seedStatement($r['query'],['trace_id'=>"x' OR 1=1"],false);check($args['trace_id']==="x' OR 1=1"&&!str_contains($sql,"x' OR 1=1"),'trace identifier bound not interpolated');
$seed=['id'=>$a,'timestamp'=>'2026-01-01T00:00:02.000000Z','level'=>'error','trace_id'=>'private-trace','request_id'=>'private-request','message'=>'timeout password=secret-value','stack_trace'=>"RuntimeException\n#0 /app.php(7): fail()\nAuthorization: Bearer hidden-token"];
$related=['id'=>$b,'timestamp'=>'2026-01-01T00:00:01.000000Z','level'=>'info','trace_id'=>'private-trace','request_id'=>'other-request','message'=>'starting'];
[$sql,$args]=Investigation::relatedStatement($r['query'],[$seed],true);check($args['project_id']===$p&&isset($args['from'],$args['to'])&&!isset($args['q'],$args['level'])&&str_contains($sql,' OR ')&&$args['trace_id_correlation_0']==='private-trace'&&$args['request_id_correlation_0']==='private-request'&&$args['seed_0']===$a,'project and time scoped one-hop expansion');
check(Investigation::relatedStatement($r['query'],[array_replace($seed,['trace_id'=>null,'request_id'=>''])],false)===null,'no empty correlation wildcard');
$c=Investigation::context([$seed],[$related,$seed],true);$wire=json_encode($c);check(array_column($c['events'],'id')===[$b,$a]&&$c['events'][0]['seed']===false&&$c['events'][1]['seed']===true,'chronological deduplicated evidence');
check($c['events'][0]['trace']===$c['events'][1]['trace']&&$c['events'][0]['request']!==$c['events'][1]['request']&&!str_contains($wire,'private-trace')&&!str_contains($wire,'private-request'),'raw correlation identifiers never serialized');
check(!str_contains($wire,'secret-value')&&!str_contains($wire,'hidden-token')&&str_contains($wire,'RuntimeException')&&$c['investigation']['stack_count']===1,'masked stack and message');
check($c['investigation']['seed_count']===1&&$c['investigation']['related_count']===1&&$c['investigation']['error_count']===1,'local evidence counts');
$m=Investigation::context([$seed],[$related],false);check(!str_contains(json_encode($m),'RuntimeException')&&!isset($m['events'][0]['message'])&&$m['investigation']['stack_count']===0,'metadata mode never exports text');
$many=[];for($i=1;$i<=30;$i++)$many[]=array_replace($related,['id'=>sprintf('44444444-4444-4444-8444-%012d',$i),'message'=>str_repeat('a',2048),'stack_trace'=>str_repeat('b',4096)]);
$c=Investigation::context([$seed],$many,true);check(count($c['events'])===20&&$c['truncated']&&$c['investigation']['text_omitted_count']>0&&strlen(json_encode($c))<60000,'bounded total evidence with explicit omissions');
$c=Investigation::context([],[],true);check($c['events']===[]&&$c['investigation']['seed_count']===0,'empty investigation no fabricated evidence');
$auto=Assistant::validate(['project_id'=>$p,'question'=>'What happened?','consent'=>true,'investigation'=>[]],1700000000);
[$sql,$args]=Investigation::seedStatement($auto['query'],[],false);check(str_contains($sql,'level IN')&&in_array('error',$args,true)&&in_array('emergency',$args,true),'automatic mode defaults to error severities');
$c=Investigation::context([array_replace($seed,['stack_trace'=>null,'stack_omitted'=>true])],[],true);check($c['events'][0]['stack_trace_omitted']&&$c['investigation']['text_omitted_count']===1,'oversized stack omission reported');
$payload=Assistant::payload('fixture-model',$r,Investigation::context([$seed],[$related],true));check(str_contains($payload['instructions'],'chronological order')&&str_contains($payload['instructions'],'proven root cause')&&!isset($payload['tools'])&&$payload['store']===false,'investigation-specific reasoning instructions');
echo "$n AI Investigation checks passed\n";
