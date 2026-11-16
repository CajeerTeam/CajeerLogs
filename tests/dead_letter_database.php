<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Queue\{PostgresQueueDriver,QueueAccounting,DeadLetterManager};
use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Http\{Request,Response};
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use disposable PostgreSQL test database.');
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();$base=$app->container()->get(PDO::class);$schema='cl_dlq_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');
$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function call(string $method,string $path,array $headers=[],?array $body=null,array $query=[],int $status=200):Response {global $app;$r=$app->handle(new Request($method,'/api/v1'.$path,['Content-Type'=>'application/json']+$headers,$query,$body===null?'':json_encode((object)$body,JSON_THROW_ON_ERROR),'192.0.2.57'));check($r->status===$status,"$method $path => $status (got {$r->status})");return $r;}
function data(Response $r):array{return json_decode($r->body,true,64,JSON_THROW_ON_ERROR);}
function login(string $email):array {$r=call('POST','/auth/login',[],['email'=>$email,'password'=>'dlq-test-password-123']);return ['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>data($r)['csrf_token']];}
try {
    $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('app.url','http://localhost:8080');$cfg->set('security.require_admin_totp',false);$cfg->set('security.required_totp_roles','');$cfg->set('security.required_totp_permissions','');$cfg->set('security.reauth_seconds',300);$cfg->set('ingest.require_hmac',false);$cfg->set('api.rate_limit',600);$cfg->set('queue.max_jobs',10);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();$queue=$app->container()->get(PostgresQueueDriver::class);$account=new QueueAccounting($pdo);
    $users=$pdo->prepare('INSERT INTO users(email,password_hash,role) VALUES(:email,:hash,:role) RETURNING id');$ids=[];foreach(['administrator','operator','viewer'] as $role){$users->execute(['email'=>$role.'@dlq.test','hash'=>password_hash('dlq-test-password-123',PASSWORD_ARGON2ID),'role'=>$role]);$ids[$role]=$users->fetchColumn();$users->closeCursor();}
    $a=login('administrator@dlq.test');$o=login('operator@dlq.test');$v=login('viewer@dlq.test');
    $projects=new ProjectRepository($pdo);$p=$projects->create('One','one');$p2=$projects->create('Two','two');$sources=new SourceRepository($pdo);$s=$sources->create($p,'App','app');$s2=$sources->create($p2,'App','app');$tokens=new TokenRepository($pdo);$t=$tokens->create('writer',['logs:write'],$p,$s);$t2=$tokens->create('writer2',['logs:write'],$p2,$s2);
    $path='/projects/'.$p;$dlq=$path.'/queue/dead';$other='/projects/'.$p2.'/queue/dead';
    call('PUT',$path.'/members/'.$ids['operator'],$a,null,[],204);call('PUT',$path.'/members/'.$ids['viewer'],$a,null,[],204);
    $event=['timestamp'=>'2026-01-01T00:00:00Z','source'=>'app','level'=>'error','message'=>'dlq-private-payload'];
    $make=function(string $key,array $token)use($event,$queue,$pdo):string{
        $headers=['Authorization'=>'Bearer '.$token['token'],'Idempotency-Key'=>$key];$id=data(call('POST','/ingest/async',$headers,$event,[],202))['job_id'];
        $c=$queue->claim();check((string)$c['id']===$id,'claimed admitted job');$pdo->exec("UPDATE jobs SET lease_until=clock_timestamp()-interval '10 minutes',max_attempts=attempts WHERE id=".$id);check(isset($queue->claim()['skipped']),'final expired attempt enters dead letter');return $id;
    };
    $j1=$make('dlq-one',$t);$j2=$make('dlq-two',$t);$foreign=$make('dlq-three',$t2);
    $page=data(call('GET',$dlq,$o,null,['limit'=>'1','source_id'=>$s,'reason'=>'attempts_exhausted','min_attempts'=>'1','min_age_seconds'=>'0']));check(count($page['items'])===1&&$page['items'][0]['id']===$j2&&$page['next_cursor']===$j2,'filtered descending page');
    $next=data(call('GET',$dlq,$o,null,['cursor'=>$page['next_cursor'],'limit'=>'1']));check($next['items'][0]['id']===$j1&&$next['next_cursor']===null,'keyset second page');
    $d=data(call('GET',$path.'/queue/jobs/'.$j1,$o));check($d['job']['id']===$j1&&$d['attempts'][0]['outcome']==='expired'&&!str_contains(json_encode($d),'dlq-private-payload')&&!isset($d['job']['payload'],$d['job']['token_id'],$d['job']['key_hash']),'metadata-only history');
    call('GET',$other,$o,null,[],403);call('GET',$dlq,$v,null,[],403);call('GET',$dlq,['Authorization'=>'Bearer '.$t['token']],null,[],403);call('GET',$path.'/queue/jobs/'.$foreign,$o,null,[],404);check(data(call('GET',$dlq,$a,null,['source_id'=>$s2]))['items']===[],'foreign source filter returns no rows');call('GET',$dlq,$o,null,['limit'=>'101'],422);
    $item=fn($id,$attempts=1)=>['id'=>$id,'attempts'=>$attempts];$body=fn($items)=>['items'=>$items,'confirm'=>true];
    call('POST',$dlq.'/retry',$o,$body([$item($j1),$item($foreign)]),[],404);check($queue->status($j1,$t['id'],$p)['status']==='dead','cross-project batch rolls back');
    call('POST',$dlq.'/retry',$o,$body([$item($j1),$item($j2,0)]),[],409);check($account->snapshot()['active_jobs']===3,'stale batch does not mutate');
    call('POST',$dlq.'/discard',['Cookie'=>$o['Cookie']],$body([$item($j1)]),[],403);call('POST',$dlq.'/discard',$o,['items'=>[$item($j1)],'confirm'=>false],[],422);
    $pdo->exec("UPDATE auth_sessions SET reauthenticated_at=clock_timestamp()-interval '1 hour' WHERE user_id='".$ids['operator']."'");call('POST',$dlq.'/retry',$o,$body([$item($j1)]),[],403);call('POST','/auth/reauthenticate',$o,['password'=>'dlq-test-password-123']);
    $role=data(call('POST','/roles',$a,['name'=>'Queue reader','scope'=>'global','permissions'=>['projects:read','queue:read']],[],201));call('PUT','/users/'.$ids['operator'].'/role-assignment',$a,['role_id'=>$role['id'],'expected_role_id'=>null],[],204);call('GET',$dlq,$o);call('POST',$dlq.'/retry',$o,$body([$item($j1)]),[],403);call('PUT','/users/'.$ids['operator'].'/role-assignment',$a,['role_id'=>null,'expected_role_id'=>$role['id']],[],204);
    // Force audit insertion failure using the actual service, without going through readiness drift protection.
    $pdo->exec("ALTER TABLE audit_log ADD CONSTRAINT dlq_test_audit CHECK (action<>'queue.retried') NOT VALID");$failed=false;try{(new TransactionManager($pdo))->run(fn()=>(new DeadLetterManager($pdo))->change($p,'retry',[$item($j1)],$ids['operator']));}catch(PDOException){$failed=true;}$pdo->exec('ALTER TABLE audit_log DROP CONSTRAINT dlq_test_audit');check($failed&&$queue->status($j1,$t['id'],$p)['status']==='dead','audit failure rolls back mutation');
    $r=data(call('POST',$dlq.'/retry',$o,$body([$item($j1),$item($j2)])));check($r['changed']===2&&$account->snapshot()['active_jobs']===3,'atomic retry preserves capacity');call('POST',$dlq.'/retry',$o,$body([$item($j1)]),[],409);
    // Both retried jobs are still the same jobs; completion commits once.
    check($queue->process($queue->claim())==='completed'&&$queue->process($queue->claim())==='completed','both original jobs complete');check($account->snapshot()['active_jobs']===1&&$account->verify()['valid'],'completion releases capacity');
    $j3=$make('dlq-discard',$t);call('POST',$dlq.'/discard',$o,$body([$item($j3)]));check($pdo->query('SELECT payload::text FROM jobs WHERE id='.$j3)->fetchColumn()==='{}'&&$account->snapshot()['active_jobs']===1,'discard erases payload and releases capacity');
    $replay=data(call('POST','/ingest/async',['Authorization'=>'Bearer '.$t['token'],'Idempotency-Key'=>'dlq-discard'],$event,[],200));check($replay['job_id']===$j3&&$replay['status']==='cancelled','discard retains replay receipt');
    check((new DeadLetterManager($pdo))->cliChange($foreign,'discard'),'CLI uses audited mutation');check($account->verify()['valid']&&$account->snapshot()['active_jobs']===0,'final accounting valid');
    $audit=data(call('GET',$path.'/security/audit',$o,null,['action'=>'queue.retried','target'=>$j1]));check(count($audit['items'])===1&&$audit['items'][0]['actor_id']===$ids['operator']&&(new AuditLogger($pdo))->verify()['valid'],'project audit target and chain valid');
    check((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='queue.discarded' AND actor_type='system'")->fetchColumn()===1,'CLI actor recorded');
    echo "Dead-letter database: $n checks passed\n";
} finally {$base->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
