<?php
declare(strict_types=1);
use Cajeer\Logs\Update\VerifiedReleaseDownloader as Downloads;
use Cajeer\Logs\Http\Request;

$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function checkDownload(bool $ok,string $label): void {global $n;if (!$ok) throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function rejectedDownload(callable $action): bool {try {$action();}catch (RuntimeException|InvalidArgumentException|JsonException $e) {return true;}return false;}
$root=sys_get_temp_dir().'/cajeer-updates-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $calls=[];
    $denied=new Downloads($root,static function(array $args) use (&$calls):string {$calls[]=$args;throw new RuntimeException('Offline GitHub API denied.');},static fn():int=>170000);
    checkDownload(rejectedDownload(fn()=>$denied->download('1.1.0','1.1.0')) && $calls===[],'never download installed version');
    checkDownload(rejectedDownload(fn()=>$denied->download('1.0.1','1.1.0')) && $calls===[],'never downgrade');
    checkDownload(rejectedDownload(fn()=>$denied->download('../release','1.0.1')) && $calls===[],'reject unsafe version');
    checkDownload(rejectedDownload(fn()=>$denied->download('1.1.0','1.0.1')) && count($calls)===1 && $calls[0][0]==='api','live API errors fail closed');
    checkDownload(!file_exists($root.'/v1.1.0'),'failed release leaves no verified directory');
    checkDownload($denied->status('1.1.0')===null,'status does not invent verified archives');
    checkDownload(rejectedDownload(fn()=>$denied->archive('1.1.0')),'unverified ZIP may not be served');
    checkDownload($app->handle(new Request('GET','/api/v1/system/updates/verified/1.1.0',secureTransport:true))->status===401,'unauthenticated verified status denied');
    checkDownload($app->handle(new Request('GET','/api/v1/system/updates/artifact/1.1.0',secureTransport:true))->status===401,'unauthenticated artifact denied');

    $certificateFor=static fn(string $sha):array=>['format'=>1,'scope'=>'database-release-and-production-smoke','status'=>'passed','version'=>'1.1.0','archive_sha256'=>$sha,
        'stages'=>[['name'=>'production-and-throughput-certification','status'=>'passed','exit_code'=>0]],
        'performance_report'=>['status'=>'passed','certified'=>true,'archive_sha256'=>$sha],
        'database_reports'=>array_map(static fn($m)=>['php_series'=>$m[0],'postgres_major'=>$m[1],'status'=>'passed','archive_sha256'=>$sha],[['8.4','17'],['8.4','18'],['8.5','17'],['8.5','18']])];
    $cert=$certificateFor(str_repeat('a',64));Downloads::validateCertificate($cert,'1.1.0',str_repeat('a',64));checkDownload(true,'matching final GA certificate accepted structurally');
    foreach (['status'=>'failed','archive_sha256'=>str_repeat('b',64),'database_reports'=>[],'performance_report'=>['status'=>'passed','certified'=>false],'stages'=>[]] as $field=>$bad) {
        checkDownload(rejectedDownload(fn()=>Downloads::validateCertificate(array_replace($cert,[$field=>$bad]),'1.1.0',str_repeat('a',64))),'reject invalid GA certificate '.$field);
    }
    if (!class_exists(ZipArchive::class)) {
        echo "SKIP archive-content tests: PHP ext-zip not installed in local runner (required in production/CI).\n";
    } else {
        $archive=$root.'/fixture.zip';
        $proc=proc_open(['python3',dirname(__DIR__).'/scripts/release.py','build',$archive],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if (!is_resource($proc)) throw new RuntimeException('Cannot build integration fixture.');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        checkDownload(proc_close($proc)===0&&is_file($archive),'actual deterministic release archive built: '.$error);
        $sha=hash_file('sha256',$archive);$version='1.1.0';$name='CajeerLogs-'.$version.'.zip';
        $certificate=json_encode($certificateFor($sha),JSON_THROW_ON_ERROR);
        $metadata=['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'published_at'=>'2026-11-24T00:00:00Z',
            'assets'=>[['name'=>$name,'state'=>'uploaded','size'=>filesize($archive),'digest'=>'sha256:'.$sha],
                       ['name'=>$name.'.sha256','state'=>'uploaded','size'=>strlen($sha.'  '.$name."\n")],['name'=>'database-certification.json','state'=>'uploaded','size'=>strlen($certificate),'digest'=>'sha256:'.hash('sha256',$certificate)]]];
        $commit=str_repeat('a',40);
        $calls=[];
        $signed=true;$certSigned=true;$alterChecksum=false;$tamperArchive=false;
        $gh=static function(array $args) use (&$calls,&$signed,&$alterChecksum,&$tamperArchive,&$certSigned,$archive,$metadata,$commit,$name,$certificate):string {
            $calls[]=$args;
            if ($args[0]==='api') {
                if (str_contains($args[3],'releases/tags/'))return json_encode($metadata,JSON_THROW_ON_ERROR);
                if (str_contains($args[3],'git/ref/tags/'))return json_encode(['ref'=>'refs/tags/v1.1.0','object'=>['type'=>'commit','sha'=>$commit]],JSON_THROW_ON_ERROR);
                throw new RuntimeException('Unexpected API path');
            }
            if ($args[0]==='release' && $args[1]==='download') {
                $destination=$args[array_search('--dir',$args,true)+1];$pattern=$args[array_search('--pattern',$args,true)+1];
                if ($pattern===$name) copy($archive,$destination.'/'.$pattern);
                elseif ($pattern==='database-certification.json') file_put_contents($destination.'/'.$pattern,$certificate);
                else file_put_contents($destination.'/'.$pattern,hash_file('sha256',$archive).'  '.$name."\n");
                if ($pattern===$name && $tamperArchive)file_put_contents($destination.'/'.$pattern,'tampered');
                if ($pattern===$name.'.sha256' && $alterChecksum)file_put_contents($destination.'/'.$pattern,str_repeat('0',64).'  '.$name."\n");
                return '';
            }
            if ($args[0]==='attestation' && $args[1]==='verify') {
                if (!$signed||(!$certSigned&&basename($args[2])==='database-certification.json'))throw new RuntimeException('No authenticated signed provenance.');
                if (!in_array('--deny-self-hosted-runners',$args,true)||!in_array('refs/tags/v1.1.0',$args,true)
                    ||!in_array($commit,$args,true)||!in_array(Downloads::REPOSITORY.'/.github/workflows/runtime.yml',$args,true))
                    throw new RuntimeException('Missing signer or source trust constraints');
                return '[{"verificationResult":{"statement":{"predicateType":"https://slsa.dev/provenance/v1"}}}]';
            }
            throw new RuntimeException('Unexpected GitHub operation');
        };
        // Helper without trusting any fake network result as a real signature:
        // the injected command simulates *successful gh cryptographic verification*;
        // production command executes gh and fails when Sigstore verification fails.
        $checker=new Downloads($root.'/download',$gh,static fn():int=>170000);
        checkDownload($checker->inspectZip($archive,'1.1.0')['postgres_major']===17,'every file digest and PHP/PostgreSQL version validated');
        checkDownload(rejectedDownload(fn()=>$checker->inspectZip($archive,'1.0.2')),'wrong ZIP version fails');
        $badDb=new Downloads($root.'/bad-db',$gh,static fn():int=>160000);
        checkDownload(rejectedDownload(fn()=>$badDb->inspectZip($archive,'1.1.0')),'older PostgreSQL rejects candidate');
        $signed=false;
        checkDownload(rejectedDownload(fn()=>$checker->download('1.1.0','1.0.1')),'unsigned ZIP blocked');
        checkDownload($checker->status('1.1.0')===null,'unsigned ZIP not saved');
        $signed=true;$certSigned=false;
        checkDownload(rejectedDownload(fn()=>$checker->download('1.1.0','1.0.1'))&&$checker->status('1.1.0')===null,'signed ZIP without signed GA certificate rejected');
        $certSigned=true;$alterChecksum=true;
        checkDownload(rejectedDownload(fn()=>$checker->download('1.1.0','1.0.1')),'bad SHA-256 sidecar blocked');
        $alterChecksum=false;$tamperArchive=true;
        checkDownload(rejectedDownload(fn()=>$checker->download('1.1.0','1.0.1')),'tampered ZIP blocked');
        $tamperArchive=false;
        $proof=$checker->download('1.1.0','1.0.1');
        checkDownload($proof['status']==='verified'&&$proof['sha256']===$sha&&$proof['provenance']==='github_oidc_sigstore'&&$proof['certification_sha256']===hash('sha256',$certificate),'verified real ZIP committed atomically');
        checkDownload($checker->verify('1.1.0')['source_commit']===$commit,'existing ZIP reverified against signed source');
        checkDownload($checker->archive('1.1.0')[1]['sha256']===$sha,'archive available only after successful verification');
        $saved=$checker->download('1.1.0','1.0.1');
        checkDownload($saved['cached']===true,'subsequent download uses checked existing archive');
        [$path]=$checker->archive('1.1.0');file_put_contents($path,'malicious');
        checkDownload($checker->status('1.1.0')===null && rejectedDownload(fn()=>$checker->download('1.1.0','1.0.1')),'post-download corruption fails closed, not silently replaced');
    }
} finally {
    $remove=function(string $path) use (&$remove):void {
        if (is_dir($path)&&!is_link($path)){foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);}elseif (file_exists($path)||is_link($path))unlink($path);
    };
    $remove($root);
}
echo "Verified Update Downloads: $n checks passed\n";
