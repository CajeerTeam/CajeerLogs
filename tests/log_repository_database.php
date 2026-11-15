<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager,DatabaseException};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{LogRepository,LogQuery,IngestEvent};
use Cajeer\Logs\Http\HttpException;
if (getenv('CAJEER_TEST_DATABASE')!=='1') { fwrite(STDERR,"Use an isolated PostgreSQL test database and CAJEER_TEST_DATABASE=1.\n");exit(1); }
$root=dirname(__DIR__);$baseApp=require $root.'/core/bootstrap.php';$baseApp->boot();
$base=$baseApp->container()->get(PDO::class);$schema='cl_repo_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$tmp=sys_get_temp_dir().'/'.$schema;mkdir($tmp,0700);$n=0;
function verify(bool $v,string $name):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function failure(callable $f):Throwable {try{$f();}catch(Throwable $e){return $e;}throw new RuntimeException('Expected failure');}
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);$app->config()->set('app.env','test');
    $pdo=$app->container()->get(PDO::class);$tx=new TransactionManager($pdo);
    foreach (['0001_initial_schema.sql','0002_projects_sources.sql','0003_identity_access.sql'] as $file) copy($root.'/migrations/pgsql/'.$file,$tmp.'/'.$file);
    (new MigrationRunner($pdo,$tmp,$schema))->migrate();
    $legacy=$pdo->query("INSERT INTO log_events(occurred_at,source,level,message,context) VALUES ('2025-01-01T00:00:00Z','app','info','password=secret','{\"api_key\":\"raw\"}') RETURNING id")->fetchColumn();
    $legacyOther=$pdo->query("INSERT INTO log_events(occurred_at,source,level,message) VALUES ('2025-01-01T00:00:00Z','other','info','legacy') RETURNING id")->fetchColumn();
    $app->container()->get(MigrationRunner::class)->migrate();
    $projects=new ProjectRepository($pdo);$sources=new SourceRepository($pdo);$tokens=new TokenRepository($pdo);
    $p=$projects->create('Repo','repo');$foreign=$projects->create('Foreign','foreign');
    $a=$sources->create($p,'App','app');$b=$sources->create($p,'Other','other');$c=$sources->create($foreign,'App','app');
    $writer=$tokens->create('writer',['logs:write'],$p);$scoped=$tokens->create('scoped',['logs:write'],$p,$a);$reader=$tokens->create('reader',['logs:read'],$p);$foreignToken=$tokens->create('foreign',['logs:write'],$foreign);
    $repo=new LogRepository($pdo);$event=new IngestEvent('app','info','repo test',(object)['password'=>'secret','number'=>'9223372036854775808'],'2026-01-01T00:00:00Z',tags:['repo']);
    $other=new IngestEvent('other','error','other test',(object)[],'2026-01-01T00:00:00Z');
    $map=['app'=>$a,'other'=>$b];$count=fn()=>(int)$pdo->query('SELECT count(*) FROM log_events')->fetchColumn();
    verify($repo->resolveSources([$event,$other],$p,null)===$map || $repo->resolveSources([$event,$other],$p,null)===array_reverse($map,true),'resolve registered sources in bulk');
    verify(failure(fn()=>$repo->resolveSources([$event,$other],$p,$a)) instanceof HttpException,'source-bound resolver rejects mixed batch');
    $ids=$tx->run(fn()=>$repo->storeBatch([$event,$other,$event],$p,$map,$writer['id']));
    verify(count(array_unique($ids))===3 && $count()===5,'real atomic three-row insert');
    $q=LogQuery::parse(['project_id'=>$p,'limit'=>'1']);$rows=iterator_to_array($repo->iterate($q),false);
    $sorted=$ids;rsort($sorted);verify(array_column($rows,'id')===$sorted,'keyset iteration over equal timestamps');
    $detail=$repo->find($q,$ids[0]);verify($detail['context']->password==='[redacted]'&&$detail['context']->number==='9223372036854775808'&&$detail['tags']===['repo'],'JSONB data and redaction round trip');
    verify($repo->find(LogQuery::parse(['project_id'=>$foreign]),$ids[0])===null && $repo->find($q->scoped($a),$ids[1])===null,'detail isolation');
    verify($repo->page(LogQuery::parse(['project_id'=>$p,'q'=>'repo','tag'=>'repo']))['items']!==[],'real full text and tag search');
    foreach ([[$p,['app'=>$c,'other'=>$b],$writer['id']],[$p,['app'=>$b,'other'=>$b],$writer['id']],[$p,$map,$scoped['id']],[$p,$map,$reader['id']],[$p,$map,$foreignToken['id']]] as $i=>[$project,$sourcesMap,$token]) {
        $before=$count();$err=failure(fn()=>$tx->run(fn()=>$repo->storeBatch([$event,$other],$project,$sourcesMap,$token)));
        verify($err instanceof HttpException && $err->status===403 && $count()===$before,'scope mismatch rejects entire batch '.$i);
    }
    // Direct repository callers cannot bypass scope checks when enqueuing or writing.
    $queue=new \Cajeer\Logs\Queue\PostgresQueueDriver($pdo);
    foreach ([[$scoped['id'],['app'=>$a,'other'=>$b]],[$reader['id'],$map],[$foreignToken['id'],$map]] as $i=>[$tokenId,$sourceMap]) {
        $err=failure(fn()=>$tx->run(fn()=>$queue->enqueue($tokenId,$p,hash('sha256','denied-'.$i),hash('sha256','body'),[$event,$other],$sourceMap)));
        verify($err instanceof HttpException&&$err->status===403,'direct enqueue rejects scope mismatch '.$i);
    }
    $badToken=$tokens->create('malformed scope',['logs:write'],$p,$a);
    foreach(['{"logs:write":true}','["logs:write","logs:write"]','["logs:write","system:read"]'] as $badScopes){
        $pdo->prepare('UPDATE api_tokens SET scopes=CAST(:scopes AS jsonb) WHERE id=:id')->execute(['scopes'=>$badScopes,'id'=>$badToken['id']]);
        $before=$count();$err=failure(fn()=>$tx->run(fn()=>$repo->storeBatch([$event],$p,['app'=>$a],$badToken['id'])));
        verify($err instanceof HttpException&&$err->status===403&&$count()===$before,'SQL rejects malformed scope without writing');
        $err=failure(fn()=>$tx->run(fn()=>$queue->enqueue($badToken['id'],$p,hash('sha256','bad-scope'),hash('sha256','body'),[$event],['app'=>$a])));
        verify($err instanceof HttpException&&$err->status===403,'queue rejects malformed scope');
    }
    $pdo->prepare('UPDATE api_tokens SET scopes=CAST(:scopes AS jsonb) WHERE id=:id')->execute(['scopes'=>'["logs:write"]','id'=>$badToken['id']]);
    verify((int)$pdo->query("SELECT count(*) FROM jobs WHERE queue='ingest-v1'")->fetchColumn()===0,'denied enqueue leaves no job');
    $before=$count();$pdo->exec("ALTER TABLE log_events ADD CONSTRAINT repo_test_failure CHECK(message<>'db failure')");
    $bad=new IngestEvent('app','info','db failure',(object)[],'2026-01-01T00:00:00Z');
    verify(failure(fn()=>$tx->run(fn()=>$repo->storeBatch([$event,$bad],$p,$map,$writer['id']))) instanceof PDOException && $count()===$before,'SQL constraint rollback of complete batch');
    $pdo->exec('ALTER TABLE log_events DROP CONSTRAINT repo_test_failure');
    verify((int)$repo->legacyCounts()['unscoped']===2 && $repo->find($q,$legacy)===null,'legacy rows excluded from ordinary reads');
    $legacyPage=$repo->legacyList(1);$legacyNext=$repo->legacyList(1,$legacyPage['next_after']);
    verify(count($legacyPage['items'])===1&&count($legacyNext['items'])===1&&$legacyNext['next_after']===null&&!isset($legacyPage['items'][0]['message']),'bounded legacy list without log content');
    $key=str_repeat('a',64);$hash=str_repeat('b',64);
    $tx->run(fn()=>$repo->saveReceipt($writer['id'],$key,$hash,$ids));
    verify($tx->run(fn()=>$repo->receipt($writer['id'],$key,$hash))===$ids,'durable receipt preserves input UUID order');
    $stmt=$pdo->prepare("UPDATE ingest_receipts SET event_ids='[]'::jsonb WHERE token_id=:token AND key_hash=:key");$stmt->execute(['token'=>$writer['id'],'key'=>$key]);
    verify(failure(fn()=>$tx->run(fn()=>$repo->receipt($writer['id'],$key,$hash))) instanceof DatabaseException,'corrupted stored receipt fails closed');
    verify(failure(fn()=>$tx->run(fn()=>$repo->adoptLegacy($legacy,$p,$b))) instanceof DatabaseException,'wrong legacy source rejected');
    $tx->run(fn()=>$repo->adoptLegacy($legacy,$p,$a));
    $adopted=$repo->find($q,$legacy);verify($adopted['message']==='password=[redacted]'&&$adopted['context']->api_key==='[redacted]'&&$adopted['id']===$legacy,'explicit adoption preserves UUID and redacts old data');
    verify((int)$repo->legacyCounts()['unscoped']===1 && $repo->find($q,$legacyOther)===null,'unmapped legacy row remains hidden');
    $stmt=$pdo->prepare("SELECT count(*) FROM audit_log WHERE action='logs.legacy_adopted' AND target=:id");$stmt->execute(['id'=>$legacy]);verify((int)$stmt->fetchColumn()===1,'legacy adoption audited');
    // Exercise the actual CLI on the same schema, including multiple rows and explicit scope.
    $proc=proc_open([PHP_BINARY,'bin/cajeer','logs:export-jsonl',$p,$a],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,array_merge(getenv(),['DB_SCHEMA'=>$schema,'APP_ENV'=>'test']));
    if (!is_resource($proc)) throw new RuntimeException('Cannot start export CLI');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
    $export=array_map(fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),explode("\n",rtrim($out,"\n")));
    verify($code===0&&count($export)===3&&array_unique(array_column($export,'source_id'))===[$a],'CLI real source-scoped JSONL export');
    echo "Log repository PostgreSQL: $n checks passed\n";
} finally {
    $base->exec('DROP SCHEMA "'.$schema.'" CASCADE');
    foreach (glob($tmp.'/*')?:[] as $file) unlink($file);rmdir($tmp);
}
