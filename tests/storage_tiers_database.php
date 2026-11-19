<?php
declare(strict_types=1);
use Cajeer\Logs\Database\{MigrationRunner,TransactionManager};
use Cajeer\Logs\Database\Repository\{ProjectRepository,SourceRepository,TokenRepository};
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{LogRepository,IngestValidator};
use Cajeer\Logs\Storage\{ArchiveLifecycle,S3CompatibleDisk};
if (getenv('CAJEER_TEST_DATABASE')!=='1') {fwrite(STDERR,"Requires disposable PostgreSQL.\n");exit(78);}
$root=dirname(__DIR__);$base=require $root.'/core/bootstrap.php';$base->boot();
$admin=$base->container()->get(PDO::class);$schema='cl_storage_'.bin2hex(random_bytes(6));$admin->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
$check=static function(bool $truth,string $message) use (&$n):void {if(!$truth)throw new RuntimeException('FAIL '.$message);++$n;echo "PASS $message\n";};
$reject=static function(callable $f):bool{try{$f();}catch(Throwable){return true;}return false;};
try {
    $app=new Application($root);$app->boot();$app->config()->set('database.schema',$schema);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();
    $p=(new ProjectRepository($pdo))->create('Archive Project','archive-project');
    $p2=(new ProjectRepository($pdo))->create('Other Project','other-project');
    $sources=new SourceRepository($pdo);$source=$sources->create($p,'Web','web');$other=$sources->create($p2,'Web','web');
    $tokens=new TokenRepository($pdo);$token=$tokens->create('Writer',['logs:write'],$p)['id'];
    $logs=new LogRepository($pdo);
    $now=gmdate('Y-m-d\TH:i:s\Z');$month=gmdate('Y-m');
    $post=static function(string $message) use ($pdo,$logs,$p,$source,$token,$now):string {
        $body=json_encode(['timestamp'=>$now,'source'=>'web','service'=>'app','host'=>'edge','level'=>'error','message'=>$message],JSON_THROW_ON_ERROR);
        $event=(new IngestValidator())->parse(new Request('POST','/api/v1/ingest',['Content-Type'=>'application/json'],[],$body));
        return (new TransactionManager($pdo))->run(fn()=> $logs->storeBatch($event,$p,['web'=>$source],$token))[0];
    };
    $a=$post('storage event A');$b=$post('storage event B');
    $archive=new ArchiveLifecycle($pdo);
    $first=$archive->stage($p,$month,100);
    $check($first['staged']===2,'real partition receives two immutable event snapshots');
    $app->container()->get(MigrationRunner::class)->assertCurrent();
    $check(true,'dynamic monthly partitions preserve migration schema integrity');
    $check($archive->stage($p,$month,100)['staged']===0,'incremental stage is idempotent');
    $check($archive->status($p)['warm_rows']===2 && $archive->status($p2)['warm_rows']===0,'warm archives isolated by project');
    $check($reject(fn()=> (new \Cajeer\Logs\Lifecycle\Retention($pdo))->set($p,30)),'new retention policy cannot bypass archived data');
    $pdo->exec("UPDATE log_events SET message='modified while warm' WHERE id='$a'");
    $check($archive->evict($p,$month,100)['evicted']===1,'eviction requires exact current event snapshot');
    $check((int)$pdo->query("SELECT count(*) FROM log_events WHERE id='$a'")->fetchColumn()===1,'modified event remains hot');
    $check($archive->restoreHot($p,$month,100)['restored']===1,'warm restore returns evicted original to hot PostgreSQL');
    $check((int)$pdo->query("SELECT count(*) FROM log_events WHERE id='$b'")->fetchColumn()===1,'restored event preserved');
    $check($archive->purgeWarm($p,$month,500)['purged']===2,'explicit warm purge removes staging copies');
    $check($archive->stage($p,$month,100)['staged']===2,'stage refreshes modified snapshots');
    $check($archive->evict($p,$month,100)['evicted']===2,'both staged snapshots can be evicted');
    $check($archive->restoreHot($p,$month,1)['restored']===1,'first paged restore processes one missing event');
    $check($archive->restoreHot($p,$month,1)['restored']===1,'second paged restore skips already restored event');
    $check($archive->restoreHot($p,$month,1)['restored']===0,'paged restore terminates after all missing rows');
    $check((int)$pdo->query("SELECT count(*) FROM log_events WHERE project_id='$p'")->fetchColumn()===2,
        'multiple restore batches preserve all original hot events');
    $port=getenv('S3_TEST_PORT');
    if ($port!==false) {
        $s3=new S3CompatibleDisk(['endpoint'=>'http://127.0.0.1:'.$port,'bucket'=>'cajeer-test','access_key'=>'TESTACCESS',
            'secret_key'=>'secret-test-signing-key','region'=>'us-east-1','path_style'=>true,'allow_insecure'=>true]);
        $cold=new ArchiveLifecycle($pdo,$s3);
        // Inject real signed HTTP failures against the S3 mock. Every failure
        // must leave both the warm rows and the database manifest unchanged.
        $faultFile=getenv('S3_TEST_FAULT_FILE');
        if ($faultFile!==false && $faultFile!=='') {
            foreach (['put_503','put_lost_ack','get_corrupt'] as $fault) {
                if (file_put_contents($faultFile,$fault)===false) throw new RuntimeException('Cannot arm S3 fault');
                $check($reject(fn()=> $cold->offload($p,$month,500)), 'S3 '.$fault.' aborts offload');
                $check($cold->status($p)['warm_rows']===2 && $cold->status($p)['cold_objects']===0,
                    'S3 '.$fault.' preserves the transactional warm archive');
            }
        }
        $uploaded=$cold->offload($p,$month,500);
        $check($uploaded['offloaded']===2 && $cold->status($p)['warm_rows']===0,'verified S3 offload removes warm rows atomically');
        $objects=$cold->coldObjects($p);$check(count($objects)===1 && $objects[0]['event_count']===2,'cold manifest written to PostgreSQL');
        $proof=$cold->verifyCold($p,$objects[0]['id']);
        $check($proof['verified'] && $proof['event_count']===2 && $proof['sha256']===$objects[0]['sha256'],
            'remote cold segment fully downloaded, hashed, decoded and bound to its catalog entry');
        $scrub=$cold->scrubCold($p,10);
        $check($scrub['verified'] && $scrub['complete'] && $scrub['verified_objects']===1 && $scrub['verified_events']===2,
            'bounded cold archive scrub independently verifies all objects and event counts');
        if ($faultFile!==false && $faultFile!=='') {
            foreach (['get_503','get_corrupt'] as $fault) {
                if (file_put_contents($faultFile,$fault)===false) throw new RuntimeException('Cannot arm S3 fault');
                $check($reject(fn()=> $cold->restoreCold($p,$objects[0]['id'])),
                    'S3 '.$fault.' aborts cold restore');
                if (file_put_contents($faultFile,$fault)===false) throw new RuntimeException('Cannot arm S3 fault');
                $check($reject(fn()=> $cold->verifyCold($p,$objects[0]['id'])),
                    'S3 '.$fault.' aborts immutable cold verification');
                $check($cold->status($p)['warm_rows']===0 && $cold->status($p)['cold_objects']===1,
                    'failed cold restore preserves manifest without incomplete warm rows');
            }
        }
        $check($cold->restoreCold($p,$objects[0]['id'])['restored']===2,'verified S3 download restores warm partition');
        $check($cold->restoreCold($p,$objects[0]['id'])['restored']===0,'cold replay is idempotent');
        if ($faultFile!==false && $faultFile!=='') {
            if (file_put_contents($faultFile,'delete_503')===false) throw new RuntimeException('Cannot arm S3 fault');
            $check($reject(fn()=> $cold->purgeCold($p,$objects[0]['id'])),
                'remote DELETE failure aborts cold purge');
            $check($cold->status($p)['cold_objects']===1,'failed DELETE retains cold metadata');
        }
        if ($faultFile!==false && $faultFile!=='') {
            // The GET has captured immutable bytes, but the PostgreSQL catalog
            // can disappear before they reach the restore process. A stale GET
            // must never recreate warm snapshots after the purge.
            $check($archive->purgeWarm($p,$month,500)['purged']===2,'prepare empty warm archive for concurrent purge test');
            $waiting=$faultFile.'.waiting';$release=$faultFile.'.release';
            @unlink($waiting);@unlink($release);
            if (file_put_contents($faultFile,'get_pause')===false) throw new RuntimeException('Cannot arm S3 delayed GET');
            $process=proc_open(['php',$root.'/tests/cold_restore_race_worker.php',$schema,(string)$port,$p,$objects[0]['id']],
                [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,$root,null,['bypass_shell'=>true]);
            if (!is_resource($process)) throw new RuntimeException('Cannot start isolated cold restore.');
            try {
                $deadline=microtime(true)+6;
                while (!is_file($waiting) && microtime(true)<$deadline) usleep(20000);
                if (!is_file($waiting)) throw new RuntimeException('Cold GET did not reach race barrier.');
                $check($cold->purgeCold($p,$objects[0]['id'])['deleted']===1,'concurrent S3 purge committed while GET was in flight');
            } finally {
                file_put_contents($release,'continue');
                $status=proc_close($process);
                @unlink($release);@unlink($waiting);
            }
            $check($status===0 && $archive->status($p)['warm_rows']===0,
                'stale downloaded S3 bytes cannot restore a segment purged during GET');
        } else {
            $check($cold->purgeCold($p,$objects[0]['id'])['deleted']===1,'explicit S3 purge deletes remote object and manifest');
        }
        $check($cold->coldObjects($p)===[],'purged cold manifest removed');
        $check($reject(fn()=> $cold->verifyCold($p,$objects[0]['id'])),
            'deleted segment cannot be certified by a stale ID');
    }
    $archive->purgeWarm($p,$month,500);
    $check((new \Cajeer\Logs\Lifecycle\Retention($pdo))->set($p,30),'retention policy allowed after archive purge');
    $check($reject(fn()=> $archive->stage($p,$month,100)),'archive refuses project with active retention');
    echo "Storage tiers PostgreSQL: $n checks passed\n";
} finally { $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE'); }
