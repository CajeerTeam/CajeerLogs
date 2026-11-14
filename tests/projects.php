<?php
declare(strict_types=1);
use Cajeer\Logs\Database\Repository\ProjectRepository as Projects;
use Cajeer\Logs\Http\{HttpException,Request};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$app->config()->set('app.env','test');
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function fails(callable $fn,int $status=422):bool{try{$fn();}catch(HttpException $e){return $e->status===$status;}return false;}
check(Projects::revision('1')==='1'&&Projects::revision('9223372036854775807')==='9223372036854775807','valid revision strings');
foreach ([0,1,'0','01','-1','9223372036854775808',null,[],str_repeat('9',20)] as $bad) check(fails(fn()=>Projects::revision($bad)),'invalid revision rejected '.json_encode($bad));
check(Projects::changes(['name'=>'Renamed','description'=>'','retention_days'=>null,'events_per_minute'=>1,'max_body_bytes'=>1024,'max_batch_events'=>1,'metadata'=>(object)[]])===['name'=>'Renamed','description'=>'','retention_days'=>null,'events_per_minute'=>1,'max_body_bytes'=>1024,'max_batch_events'=>1,'metadata'=>'{}'],'valid patch and nullable policies');
foreach ([[],['name'=>' '],['description'=>str_repeat('x',4097)],['metadata'=>[]],['metadata'=>(object)['large'=>str_repeat('x',8192)]],['retention_days'=>0],['events_per_minute'=>'1'],['max_body_bytes'=>1048577],['max_batch_events'=>101],['slug'=>'changed'],['archived_at'=>null],['name'=>"x\0y"]] as $bad) check(fails(fn()=>Projects::changes($bad)),'invalid patch rejected '.implode(',',array_keys($bad)));
$row=Projects::decode(['metadata'=>'{}','revision'=>2,'archived_at'=>null,'max_body_bytes'=>'1024','retention_days'=>null]);
check($row['metadata'] instanceof stdClass && $row['revision']==='2'&&$row['status']==='active'&&$row['max_body_bytes']===1024,'decoded project has stable JSON types');
check(Projects::decode(['archived_at'=>'2026-01-01'])['status']==='archived','archive state derived from timestamp');
$id='11111111-1111-4111-8111-111111111111';foreach(['PATCH /projects/'.$id,'POST /projects/'.$id.'/archive','POST /projects/'.$id.'/restore','GET /projects/'.$id.'/stats'] as $route){[$method,$path]=explode(' ',$route);check($app->handle(new Request($method,'/api/v1'.$path))->status===401,'protected '.$route);}
echo "Project Management: $n checks passed\n";
