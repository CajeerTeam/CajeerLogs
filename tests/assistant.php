<?php
declare(strict_types=1);
use Cajeer\Logs\AI\{Assistant,OpenAIClient};
use Cajeer\Logs\Http\{Request,HttpException};
$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot(); $app->config()->set('app.env','test');
$n=0; function check(bool $ok,string $name):void {global $n; if(!$ok)throw new RuntimeException('FAIL '.$name); ++$n; echo "PASS $name\n";}
function reject(callable $fn,int $status):bool {try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
$p='11111111-1111-4111-8111-111111111111';$id='22222222-2222-4222-8222-222222222222';
$base=['project_id'=>$p,'question'=>'Investigate password=secret123','keyword'=>'timeout','hours'=>24,'level'=>'error','consent'=>true];
$r=Assistant::validate($base,1700000000);check(!str_contains($r['question'],'secret123'),'question redacted');
check($r['query']->filters['project_id']===$p&&$r['query']->filters['q']==='timeout'&&$r['query']->filters['level']==='error','project and search filters');
foreach([['consent'=>false],['project_id'=>'bad'],['hours'=>169],['hours'=>'24'],['question'=>str_repeat('x',2001)],['history'=>[['role'=>'system','content'=>'bad']]],['other_project'=>$id],['level'=>'bad'],['keyword'=>['bad']]] as $bad)check(reject(fn()=>Assistant::validate(array_replace($base,$bad)),422),'reject '.array_key_first($bad));
[$sql,$params]=Assistant::statement($r['query'],false);check(!str_contains($sql,'SELECT message')&&!str_contains($sql,'AS message')&&str_contains($sql,'LIMIT 21')&&$params['project_id']===$p&&$params['q']==='timeout','metadata-only bounded parameterized retrieval');
[$sql,$params]=Assistant::statement($r['query'],true);check(str_contains($sql,'octet_length(message)<=2048')&&!str_contains($sql,'context'),'bounded text projection excludes context');
$row=['id'=>$id,'timestamp'=>'2026-11-24T01:00:00Z','level'=>'error','message'=>'Authorization: Bearer abc-secret','context'=>['secret'=>'x']];
$c=Assistant::context(array_fill(0,21,$row),true);check(count($c['events'])===20&&$c['truncated']&&!str_contains(json_encode($c),'abc-secret')&&!isset($c['events'][0]['context']),'mask and cap log context');
$c=Assistant::context([$row],false);check(!isset($c['events'][0]['message'])&&!$c['messages_included'],'no raw message by default');
$r=Assistant::validate($base+['history'=>[['role'=>'user','content'=>'earlier question'],['role'=>'assistant','content'=>'earlier answer']]],1700000000);
$payload=Assistant::payload('gpt-test',$r,$c);check($payload['store']===false&&count($payload['input'])===3&&!isset($payload['tools'])&&$payload['max_output_tokens']===1200,'bounded multi-turn payload without tools');
$response=['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'Evidence '.$id]]]],'usage'=>['total_tokens'=>123]];
$client=new OpenAIClient('test-key',static function($url,$body,$key)use($response){check($url===OpenAIClient::URL&&$key==='test-key'&&!str_contains($body,'test-key'),'pinned provider transport');return [200,json_encode($response)];});
$a=$client->answer($payload);check($a['answer']==='Evidence '.$id&&$a['usage']->total_tokens===123,'decode real Responses shape');
foreach([[429,'{}',429],[500,'{}',503],[200,'bad',503],[200,'{"status":"incomplete","output":[]}',503]] as [$status,$raw,$expected])check(reject(fn()=>(new OpenAIClient('test-key',fn()=>[$status,$raw]))->answer($payload),$expected),'reject provider response '.$status.' '.$expected);
foreach([['GET','/assistant/status'],['POST','/assistant/chat']] as [$method,$path])check($app->handle(new Request($method,'/api/v1'.$path))->status===401,'unauthenticated '.$path);
check($app->handle(new Request('POST','/api/v1/assistant/chat',['Authorization'=>'Bearer cl_'.str_repeat('a',64)]))->status===403,'token cannot call assistant');
echo "$n Assistant checks passed\n";
