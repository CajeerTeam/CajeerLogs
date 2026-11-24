<?php
declare(strict_types=1);
use Cajeer\Logs\Update\{SemVer,GitHubReleaseClient,UpdateResolver};
use Cajeer\Logs\Http\Request;

$app=require dirname(__DIR__).'/core/bootstrap.php';
$app->boot();
$n=0;
function check(bool $condition,string $description): void { global $n; if (!$condition) throw new RuntimeException('FAIL '.$description); ++$n;echo "PASS $description\n"; }
function rejected(callable $run): bool {try {$run();}catch(InvalidArgumentException|RuntimeException) {return true;}return false;}
check(SemVer::compare('1.0.1','1.0.0')>0 && SemVer::compare('v1.0.1','1.0.1+sha.123')===0,'SemVer core and build metadata');
check(SemVer::compare('1.0.1-rc.10','1.0.1-rc.2')>0 && SemVer::compare('1.0.1-rc.2','1.0.1')<0,'numeric pre-release and stable precedence');
check(SemVer::compare('9999999999999999999999.0.0','10000000000000000000000.0.0')<0,'large numeric components compare exactly');
check(SemVer::compare('1.0.0-alpha','1.0.0-alpha.1')<0 && SemVer::compare('1.0.0-alpha.1','1.0.0-alpha.beta')<0,'SemVer pre-release element order');
foreach (['1.01.0','1.0.0-01','1.0.0+','x1.0.0','1.0','1.0.0-','1.0.0-alpha..beta','1.0.0-ä'] as $bad) check(rejected(fn()=>SemVer::parse($bad)),'reject malformed SemVer '.$bad);
$fake=static fn(string $tag,bool $draft=false,bool $pre=false,string $body='Release notes'): array=>[
    'tag_name'=>$tag,'draft'=>$draft,'prerelease'=>$pre,'published_at'=>'2026-11-24T00:00:00Z',
    'name'=>'Release '.$tag,'body'=>$body,'html_url'=>'https://evil.example/phishing',
];
$pages=0;
$releases=[
    $fake('v1.0.0'),$fake('v1.0.3-rc.10',false,true),$fake('v1.0.2'),
    $fake('v1.0.9',true),$fake('not-a-version'),$fake('v1.0.3-rc.2',false,true),
];
$transport=static function(string $url) use (&$pages,$releases): array {
    ++$pages;
    check(str_starts_with($url,'https://api.github.com/repos/CajeerTeam/CajeerLogs/releases?'), 'pinned GitHub API destination');
    return [200,json_encode($releases,JSON_THROW_ON_ERROR)];
};
$cache=sys_get_temp_dir().'/cl-updates-'.bin2hex(random_bytes(8));mkdir($cache,0700);
try {
    $resolver=new UpdateResolver($cache,new GitHubReleaseClient($transport));
    $stable=$resolver->check('1.0.1','stable');
    check($stable['candidate']==='1.0.2' && $stable['update_available']===true && $stable['status']==='ok','newest non-draft stable release');
    check($stable['release']['url']==='https://github.com/CajeerTeam/CajeerLogs/releases/tag/v1.0.2','release link pinned against forged upstream URL');
    $cached=$resolver->check('1.0.1','stable');
    check($cached['cached']===true && $pages===1,'process-shared release cache avoids extra request');
    $preview=$resolver->check('1.0.1','preview');
    check($preview['candidate']==='1.0.3-rc.10' && $preview['release']['prerelease']===true,'preview channel allows SemVer pre-release');
    $old=$resolver->check('2.0.0','stable');
    check($old['candidate']==='1.0.2' && !$old['update_available'],'existing newer installation never downgraded');
    $resolver->check('1.0.1','stable',true);
    check($pages===4,'forced refresh actually queries GitHub');
    $empty=new UpdateResolver(null,new GitHubReleaseClient(static fn(string $url): array=>[200,'[]']));
    $none=$empty->check('1.0.1','stable');
    check($none['status']==='no_releases' && !$none['update_available'] && $none['release']===null,'no release is not an update');
    $errors=[403=>'GitHub API rate limit',404=>'GitHub releases repository',503=>'HTTP 503'];
    foreach ($errors as $status=>$message) {
        try {(new GitHubReleaseClient(static fn(string $url):array=>[$status,'{}']))->listPublished();throw new RuntimeException('Missing status failure');}
        catch (RuntimeException $e) {check(str_contains($e->getMessage(),$message),'upstream HTTP '.$status.' fails closed');}
    }
    check(rejected(fn()=>(new GitHubReleaseClient(static fn(string $url):array=>[200,'{}']))->listPublished()),'unexpected object body rejected');
    check(rejected(fn()=>(new GitHubReleaseClient(static fn(string $url):array=>[200,str_repeat('x',524289)]))->listPublished()),'oversized response rejected');
    check(rejected(fn()=>$resolver->check('1.0.1','unknown')),'invalid channel rejected');
    $notes=str_repeat('Описание релиза. ',1400);
    $long=(new UpdateResolver(null,new GitHubReleaseClient(static fn(string $url):array=>[200,json_encode([$fake('v1.0.4',false,false,$notes)],JSON_THROW_ON_ERROR)])))->check('1.0.1','stable');
    check(strlen($long['release']['changelog'])<17000 && str_contains($long['release']['changelog'],'truncated'),'UTF-8 release changelog bounded');
    // The shipped CLI still supports trusted metadata without needing PostgreSQL.
    $fixture=$cache.'/trusted-release.json';
    file_put_contents($fixture,json_encode(['name'=>'CajeerLogs','version'=>'1.0.5-rc.10'],JSON_THROW_ON_ERROR));
    $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/cajeer','update:check',$fixture],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if (!is_resource($proc)) throw new RuntimeException('Could not launch update checker CLI.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    $result=json_decode($out,true,16,JSON_THROW_ON_ERROR);
    check($exit===0 && $err==='' && $result['candidate']==='1.0.5-rc.10'
        && $result['source']==='local_release_metadata' && $result['update_available']===true,
        'real CLI trusted-metadata mode compares SemVer without DB');
    // Exposed API is under identity authentication, not a public update proxy.
    check($app->handle(new Request('GET','/api/v1/system/updates',secureTransport:true))->status===401,'unauthenticated release endpoint denied');
    check($app->handle(new Request('GET','/api/v1/system/updates',query:['refresh'=>'1'],secureTransport:true))->status===401,'unauthenticated refresh denied');
} finally {
    foreach (glob($cache.'/*')?:[] as $file) unlink($file);
    rmdir($cache);
}
echo "GitHub Releases Discovery: $n checks passed\n";
