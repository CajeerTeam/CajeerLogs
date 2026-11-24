<?php
declare(strict_types=1);
use Cajeer\Logs\AI\{Assistant,Incidents,Investigation,OpenAIClient};
use Cajeer\Logs\Http\{HttpException,Request};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$app->config()->set('app.env','test');
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejects(callable $f,int $status=422):bool{try{$f();}catch(HttpException $e){return $e->status===$status;}return false;}
$p='11111111-1111-4111-8111-111111111111';$base=['project_id'=>$p,'question'=>'Find probable cause and recommendations','consent'=>true,'hours'=>24];
$r=Assistant::validate($base+['incident'=>true,'save_history'=>true],1700000000);check($r['incident']&&$r['save_history']&&$r['investigation']===null,'valid incident and explicit history opt-in');
check(!Assistant::validate($base)['save_history'],'history disabled by default');
foreach([['incident'=>'true'],['save_history'=>true],['incident'=>true,'investigation'=>[]],['incident'=>true,'save_history'=>1]] as $bad)check(rejects(fn()=>Assistant::validate($base+$bad)),'reject invalid incident/save combination');
[$sql,$args]=Incidents::statement($r['query'],false);check(str_contains($sql,'LIMIT 201')&&$args['project_id']===$p&&isset($args['from'],$args['to'])&&!str_contains($sql,'message')&&in_array('error',$args,true),'bounded project-scoped metadata error query');
[$sql,$args]=Incidents::statement(Assistant::validate($base+['level'=>'info','keyword'=>'db'])['query'],true);check(in_array('critical',$args,true)&&!in_array('info',$args,true)&&$args['q']==='db'&&str_contains($sql,'octet_length(message)<=2048'),'incident filters errors and respects keyword');
$row=fn($n,$msg,$trace,$src='api')=>['id'=>sprintf('22222222-2222-4222-8222-%012d',$n),'timestamp'=>sprintf('2026-01-01T00:00:%02d.000000Z',$n%60),'level'=>'error','source'=>$src,'service'=>'backend','message'=>$msg,'trace_id'=>$trace,'request_id'=>null,'stack_trace'=>"RuntimeException\n#0 /app.php(7): fail()"];
$a=$row(1,'Connection timeout at 123 password=secret-A','trace-a');$b=$row(2,'Connection timeout at 999 password=secret-B','trace-b');$c=$row(3,'Different error','trace-a');$d=$row(4,'Connection timeout at 999 password=secret-B','trace-c','other');
$g=Incidents::group([$a,$b,$c,$d,$a],true);$s=$g['summary'];check($s['sampled_errors']===4&&$s['group_count']===3&&$s['groups'][0]['sample_count']===2&&$s['groups'][0]['representative_id']===$b['id'],'normalize dynamic values, deduplicate IDs, isolate source and distinct error');
check($s['groups'][0]['first_seen']===$a['timestamp']&&$s['groups'][0]['last_seen']===$b['timestamp'],'group time range');
check(!str_contains(json_encode($s),'secret-A')&&!str_contains(json_encode($s),'trace-a')&&!str_contains(json_encode($s),'Connection timeout'),'summary exports no raw fingerprint inputs');
$m=Incidents::group([$a,$b,$c,$d],false)['summary'];check($m['group_count']===3&&$m['groups'][0]['basis']==='trace'&&$m['groups'][0]['sample_count']===2,'metadata-only grouping ignores message content');
$missing=array_replace($a,['trace_id'=>null,'request_id'=>'private-request']);$x=array_replace($b,['trace_id'=>null,'request_id'=>'private-request']);check(Incidents::group([$missing,$x],false)['summary']['groups'][0]['basis']==='request','request fallback');
check(Incidents::group([array_replace($a,['trace_id'=>null]),array_replace($b,['trace_id'=>null])],false)['summary']['group_count']===2,'uncorrelated events remain separate');
$many=[];for($i=1;$i<=201;$i++)$many[]=$row($i,'x','trace-'.$i);$g=Incidents::group($many,false);check($g['summary']['sampled_errors']===200&&$g['summary']['group_count']===200&&count($g['seeds'])===8&&$g['summary']['sample_truncated']&&$g['summary']['groups_truncated'],'bounded 200 sampled errors and eight representatives');
check(Incidents::group([],true)['summary']['groups']===[],'empty input never fabricates incident');
$g=Incidents::group([$a,$b,$c],true);$context=Investigation::context($g['seeds'],[],true);$context['incidents']=$g['summary'];$payload=Assistant::payload('fixture-model',$r,$context);check(str_contains($payload['instructions'],'prioritized read-only diagnostic')&&str_contains($payload['instructions'],'alternative explanations')&&$payload['store']===false&&!isset($payload['tools']),'reasoning instructions require hypotheses and recommendations without execution');
$wire=json_encode($payload);check(!str_contains($wire,'secret-A')&&!str_contains($wire,'secret-B')&&!str_contains($wire,'trace-a')&&str_contains($wire,'incident-1'),'provider input redacted and contains local group identifiers');
$response=['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'Probable timeout. Verify connection pool before changing settings.']]]]];
$answer=(new OpenAIClient('fixture-key',fn()=>[200,json_encode($response)]))->answer($payload);check(str_contains($answer['answer'],'Verify connection pool'),'Responses API analysis reaches caller');
foreach(['GET','DELETE'] as $method){check($app->handle(new Request($method,'/api/v1/assistant/history'))->status===401,'unauthenticated history '.$method);check($app->handle(new Request($method,'/api/v1/assistant/history',['Authorization'=>'Bearer cl_'.str_repeat('a',64)]))->status===403,'token cannot use history '.$method);}
echo "$n AI Incident Intelligence checks passed\n";
