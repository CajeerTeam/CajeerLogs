<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{ConnectionFactory,MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\ProjectRepository;
use Cajeer\Logs\Audit\AuditLogger;
if(getenv('CAJEER_TEST_DATABASE')!=='1'){fwrite(STDERR,"Use a disposable PostgreSQL database (CAJEER_TEST_DATABASE=1).\n");exit(1);}
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();
function session($app,string $schema):PDO{$c=clone $app->config();$c->set('database.schema',$schema);return (new ConnectionFactory($c))->make('pgsql');}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function state(Throwable $e):string{return $e instanceof PDOException?(string)($e->errorInfo[0]??$e->getCode()):'';}
// Independent process: two conflicting lock orders force a real PostgreSQL deadlock.
if(($argv[1]??'')==='deadlock-child'){
    $p=session($app,$argv[2]);$m=new TransactionManager($p);$attempts=0;
    $m->run(function()use($p,&$attempts){
        ++$attempts;$p->exec('UPDATE tx_probe SET val=val+1 WHERE id=2');
        if($attempts===1){echo "locked\n";fflush(STDOUT);if(trim((string)fgets(STDIN))!=='go')throw new RuntimeException('Missing parent signal');}
        $p->exec('UPDATE tx_probe SET val=val+1 WHERE id=1');
    },maxAttempts:3);
    echo json_encode(['attempts'=>$attempts],JSON_THROW_ON_ERROR)."\n";exit(0);
}
$base=$app->container()->get(PDO::class);$schema='cl_tx_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$child=null;$pipes=[];
try{
    $p=session($app,$schema);$other=session($app,$schema);$m=new TransactionManager($p);
    (new MigrationRunner($p,$root.'/migrations/pgsql',$schema))->migrate();
    $p->exec('CREATE TABLE tx_probe(id integer PRIMARY KEY,val integer NOT NULL); INSERT INTO tx_probe VALUES(1,0),(2,0)');
    check($m->run(fn()=> $p->query('SHOW transaction_isolation')->fetchColumn(),isolation:'SERIALIZABLE')==='serializable','actual serializable isolation');
    check($m->run(fn()=> $p->query('SHOW transaction_read_only')->fetchColumn(),isolation:'REPEATABLE READ',readOnly:true)==='on','actual read-only isolation');
    $m->run(function(TransactionManager $tx)use($p){
        $p->exec('UPDATE tx_probe SET val=10 WHERE id=1');
        try{$tx->run(function()use($p){$p->exec('UPDATE tx_probe SET val=99 WHERE id=2');$p->exec('INSERT INTO tx_probe VALUES(1,0)');});}catch(PDOException $e){if(state($e)!=='23505')throw $e;}
        $tx->run(fn()=> $p->exec('UPDATE tx_probe SET val=20 WHERE id=2'));
    });
    check(array_map('intval',$other->query('SELECT val FROM tx_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))===[10,20],'savepoint rollback and outer commit visible on independent connection');
    try{$m->run(function()use($p){$p->exec('UPDATE tx_probe SET val=999 WHERE id=1');try{$p->exec('INSERT INTO tx_probe VALUES(1,0)');}catch(PDOException){}return true;});throw new RuntimeException('Unexpected success');}catch(PDOException $e){check(state($e)==='25P02','swallowed SQL error rejected before commit');}
    check((int)$other->query('SELECT val FROM tx_probe WHERE id=1')->fetchColumn()===10,'aborted transaction did not persist update');
    // Competing session commits after the serializable snapshot; first attempt must get 40001.
    $attempts=0;
    $m->run(function()use($p,$other,&$attempts){
        ++$attempts;$p->query('SELECT val FROM tx_probe WHERE id=1')->closeCursor();
        if($attempts===1)$other->exec('UPDATE tx_probe SET val=val+1 WHERE id=1');
        $p->exec('UPDATE tx_probe SET val=val+1 WHERE id=1');
    },isolation:'SERIALIZABLE',maxAttempts:3);
    check($attempts===2&&(int)$other->query('SELECT val FROM tx_probe WHERE id=1')->fetchColumn()===12,'real serialization failure retried exactly once');
    // Test actual conflicting lock order, using separate PHP and PDO sessions.
    $p->exec('UPDATE tx_probe SET val=0');
    $child=proc_open([PHP_BINARY,__FILE__,'deadlock-child',$schema],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($child))throw new RuntimeException('Cannot start child');
    stream_set_timeout($pipes[1],20);$attempts=0;
    $m->run(function()use($p,&$attempts,$pipes){
        ++$attempts;$p->exec('UPDATE tx_probe SET val=val+1 WHERE id=1');
        if($attempts===1){if(trim((string)fgets($pipes[1]))!=='locked')throw new RuntimeException('Child did not acquire lock');fwrite($pipes[0],"go\n");fflush($pipes[0]);}
        $p->exec('UPDATE tx_probe SET val=val+1 WHERE id=2');
    },maxAttempts:3);
    $childResult=json_decode((string)fgets($pipes[1]),true,8,JSON_THROW_ON_ERROR);foreach($pipes as $pipe)fclose($pipe);$pipes=[];$exit=proc_close($child);$child=null;
    check($exit===0&&$attempts+$childResult['attempts']>=3&&array_map('intval',$other->query('SELECT val FROM tx_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))===[2,2],'actual deadlock retried; both transactions committed exactly once');
    // Audit and business mutation share one real transaction.
    $repo=new ProjectRepository($p);$audit=new AuditLogger($p);
    try{$m->run(function()use($repo,$audit){$id=$repo->create('Rollback','rollback');$audit->record('project.created',[],null,$id);throw new RuntimeException('rollback probe');});}catch(RuntimeException $e){if($e->getMessage()!=='rollback probe')throw $e;}
    check((int)$other->query("SELECT count(*) FROM projects WHERE slug='rollback'")->fetchColumn()===0&&(int)$other->query("SELECT count(*) FROM audit_log WHERE action='project.created'")->fetchColumn()===0,'business mutation and audit rollback together');
    $id=$m->run(function()use($repo,$audit){$id=$repo->create('Committed','committed');$audit->record('project.created',[],null,$id);return $id;});
    check((int)$other->query("SELECT count(*) FROM projects p JOIN audit_log a ON a.target=p.id::text WHERE p.slug='committed' AND a.action='project.created'")->fetchColumn()===1,'business mutation and audit commit together');
    // A killed backend cannot be reused or automatically replayed.
    $pid=(int)$p->query('SELECT pg_backend_pid()')->fetchColumn();$attempts=0;
    try{$m->run(function()use($p,$other,$pid,&$attempts){++$attempts;$p->exec('UPDATE tx_probe SET val=100');$other->query('SELECT pg_terminate_backend('.$pid.',5000)')->closeCursor();$p->query('SELECT 1');},maxAttempts:3);throw new RuntimeException('Unexpected success');}catch(Throwable $e){check($attempts===1&&!TransactionManager::usable($p),'terminated session not replayed');}
    check(array_map('intval',$other->query('SELECT val FROM tx_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))===[2,2],'connection loss rolls back uncommitted writes');
    echo "Transaction PostgreSQL integration passed\n";
}finally{
    if(is_resource($child)){proc_terminate($child);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child);}
    unset($p,$other,$m,$repo,$audit);$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
