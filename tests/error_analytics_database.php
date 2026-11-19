<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{ErrorAnalytics,IngestValidator,LogQuery,LogRepository};
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Requires disposable PostgreSQL with CAJEER_TEST_DATABASE=1.\n");exit(78);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_errors_'.bin2hex(random_bytes(7));
$admin->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function checkError(bool $test,string $title):void {global $n;if(!$test)throw new RuntimeException('FAIL '.$title);++$n;echo "PASS $title\n";}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $projects=new ProjectRepository($pdo);$p=$projects->create('Error project','error-project');$other=$projects->create('Another project','another-project');
    $sources=new SourceRepository($pdo);$a=$sources->create($p,'Api','api');$b=$sources->create($p,'Worker','worker');$foreign=$sources->create($other,'Api','api');
    $tokens=new TokenRepository($pdo);$token=$tokens->create('Writer',['logs:write'],$p)['id'];$tokenOther=$tokens->create('Writer',['logs:write'],$other)['id'];
    $repo=new LogRepository($pdo);$errors=new ErrorAnalytics($pdo);
    $start=new DateTimeImmutable('@'.(time()-7200));
    $fmt=static fn(DateTimeImmutable $d):string=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $eventTime=static fn(string $modifier):string=>$fmt($modifier==='' ? $start : $start->modify($modifier));
    $send=static function(string $project,string $source,string $token,string $service,string $level,string $message,string $time)use($pdo,$repo):string {
        $data=['timestamp'=>$time,'source'=>$source===''? '': ($service==='worker'?'worker':'api'),'service'=>$service,'level'=>$level,'message'=>$message];
        $events=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],json_encode($data,JSON_THROW_ON_ERROR)));
        return (new TransactionManager($pdo))->run(fn()=>$repo->storeBatch($events,$project,[$data['source']=>$source],$token))[0];
    };
    $previous=$send($p,$a,$token,'web','error','Request 999 failed for 10.2.3.4',$eventTime('-30 minutes'));
    $one=$send($p,$a,$token,'web','error','Request 123 failed for 192.168.0.1',$eventTime('+10 minutes'));
    $two=$send($p,$a,$token,'web','critical','Request 456 failed for 192.168.0.2',$eventTime('+15 minutes'));
    $worker=$send($p,$b,$token,'worker','emergency','Request 777 failed for 192.168.0.4',$eventTime('+20 minutes'));
    $info=$send($p,$a,$token,'web','info','Request 666 failed for 192.168.0.8',$eventTime('+22 minutes'));
    $otherId=$send($other,$foreign,$tokenOther,'web','alert','Request 123 failed for 192.168.0.1',$eventTime('+10 minutes'));
    $filters=['project_id'=>$p,'from'=>$eventTime(''),'to'=>$eventTime('+60 minutes'),'interval'=>'hour'];
    $q=LogQuery::parse($filters,'errors');$report=$errors->report($q);
    checkError($report['total']===3 && $report['previous_total']===1 && $report['group_count']===2,'current/previous exact totals and separate services');
    $shared=array_values(array_filter($report['groups'],fn($g)=>$g['count']===2));
    checkError(count($shared)===1 && $shared[0]['previous_count']===1 && $shared[0]['classification']==='regressed','numeric and IP identifiers collapse into stable fingerprint');
    checkError($report['groups'][1]['classification']==='new'||$report['groups'][0]['classification']==='new','new groups detected');
    $sig=$shared[0]['fingerprint'];
    $q2=LogQuery::parse(array_diff_key($filters,['interval'=>true])+['error_fingerprint'=>$sig],'list');
    checkError(count($repo->page($q2)['items'])===2,'fingerprint drilldown includes only matching events');
    checkError(count($repo->page($q2->scoped($b))['items'])===0,'source-bound drilldown enforces isolation');
    $rOther=$errors->report(LogQuery::parse(['project_id'=>$other,'from'=>$filters['from'],'to'=>$filters['to']],'errors'));
    checkError($rOther['total']===1 && $rOther['groups'][0]['fingerprint']!==$sig,'projects cannot share fingerprints or results');
    $csv=ErrorAnalytics::csv($report);checkError(str_contains($csv,'summary,')&&str_contains($csv,'group,')&&str_contains($csv,'bucket,'),'report CSV includes totals, group rows and trend');
    $pdo->beginTransaction();$pdo->exec("UPDATE log_events SET level='warning' WHERE id='$one'");$pdo->rollBack();
    checkError($errors->report($q)['total']===3,'transaction rollback cannot affect analytics');
    $pdo->exec("UPDATE log_events SET level='warning' WHERE id='$one'");
    checkError($errors->report($q)['total']===2,'level update immediately retracts group contribution');
    $pdo->exec("DELETE FROM log_events WHERE id='$two'");
    checkError($errors->report($q)['total']===1 && $errors->report($q)['group_count']===1,'delete immediately retracts group contribution');
    $pdo->exec("UPDATE log_events SET service='web' WHERE id='$worker'");
    checkError($errors->report($q)['total']===1,'service move retains exact total');
    echo "Error analytics PostgreSQL: $n checks passed\n";
} finally {$admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
