<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Http\{Request,HttpException};
use Cajeer\Logs\Security\{RateLimiter,RateLimitPolicy};
use Cajeer\Logs\Lifecycle\Maintenance;
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('APP_ENV')!=='test') throw new RuntimeException('Use a disposable PostgreSQL database.');
$root=dirname(__DIR__);$app=require $root.'/core/bootstrap.php';$app->boot();
if(($argv[1]??'')==='--worker') {
    $app->config()->set('database.schema',$argv[2]);$pdo=$app->container()->get(PDO::class);$rate=new RateLimiter($pdo);$allowed=0;
    for($i=0;$i<10;$i++) {try{$rate->enforceBucket('concurrency-test',5,5);++$allowed;}catch(HttpException $e){if($e->status!==429)throw $e;}}
    echo $allowed;exit;
}
$base=$app->container()->get(PDO::class);$schema='cl_rate_'.bin2hex(random_bytes(8));$base->exec('CREATE SCHEMA "'.$schema.'"');$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function denial(callable $fn):int{try{$fn();}catch(HttpException $e){if($e->status===429)return (int)$e->headers['Retry-After'];throw $e;}throw new RuntimeException('Expected 429.');}
try {
    $app=new Application($root);$app->boot();$cfg=$app->config();$cfg->set('database.schema',$schema);$cfg->set('app.env','test');$cfg->set('security.require_admin_totp',false);$cfg->set('security.required_totp_roles','');$cfg->set('security.required_totp_permissions','');$cfg->set('security.reauth_seconds',0);$cfg->set('api.rate_limit',600);
    $pdo=$app->container()->get(PDO::class);$app->container()->get(MigrationRunner::class)->migrate();$limiter=new RateLimiter($pdo);
    $limiter->enforceBucket('slow',60,2);$limiter->enforceBucket('slow',60,2);check(denial(fn()=>$limiter->enforceBucket('slow',60,2))===1,'burst exhausted and precise refill delay');
    $key=hash('sha256','v1:slow:60:2');$pdo->prepare('UPDATE api_rate_buckets SET updated_us=updated_us-1000000 WHERE bucket_key=:key')->execute(['key'=>$key]);$limiter->enforceBucket('slow',60,2);check(denial(fn()=>$limiter->enforceBucket('slow',60,2))===1,'one token replenishes, no full reset');
    $limiter->enforceBucket('other',60,2);check(true,'separate principal has independent budget');
    // Freeze refill in the test only, then race independent PHP processes and connections.
    $key=hash('sha256','v1:concurrency-test:5:5');$pdo->prepare("INSERT INTO api_rate_buckets VALUES(:key,300000000,(extract(epoch FROM clock_timestamp())*1000000)::bigint+60000000,clock_timestamp()+interval '2 hours')")->execute(['key'=>$key]);
    $children=[];for($i=0;$i<4;$i++){$p=proc_open([PHP_BINARY,__FILE__,'--worker',$schema],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('worker start failed');fclose($pipes[0]);$children[]=[$p,$pipes];}
    $total=0;foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0||!ctype_digit($out))throw new RuntimeException('worker failed: '.$err);$total+=(int)$out;}check($total===5,'four independent workers admit exactly five of forty attempts');
    $pdo->exec("UPDATE api_rate_buckets SET expires_at=clock_timestamp()-interval '1 second'");$counts=(new Maintenance($pdo))->authCleanup();check($counts['api_buckets']>=3&&(int)$pdo->query('SELECT count(*) FROM api_rate_buckets')->fetchColumn()===0,'expired buckets removed by real maintenance');
    // Real HTTP dispatch: low operation budget, unrelated operation remains available.
    $password='rate-test-password-123';(new UserRepository($pdo))->create('admin@rate.test',$password,'administrator');
    $call=function(string $method,string $path,array $headers=[],?array $body=null,array $query=[],string $peer='192.0.2.80')use($app){return $app->handle(new Request($method,'/api/v1'.$path,['Content-Type'=>'application/json']+$headers,$query,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),$peer));};
    $r=$call('POST','/auth/login',[],['email'=>'admin@rate.test','password'=>$password]);check($r->status===200,'admin login');$data=json_decode($r->body,true);$auth=['Cookie'=>explode(';',$r->header('Set-Cookie'),2)[0],'X-CSRF-Token'=>$data['csrf_token']];
    $r=$call('POST','/projects',$auth,['name'=>'Rate test','slug'=>'rate-test']);check($r->status===201,'project created');$project=json_decode($r->body,true)['id'];
    $cfg->set('api.rate_search',1);$q=['project_id'=>$project];$r=$call('GET','/logs',$auth,null,$q);check($r->status===200,'first search allowed');$r=$call('GET','/logs',$auth,null,$q);check($r->status===429&&(int)$r->header('Retry-After')>=1&&(int)$r->header('Retry-After')<=60,'search budget enforced by real API');
    check($call('GET','/projects',$auth)->status===200,'search budget does not block project navigation');
    $cfg->set('api.rate_limit',1);$r=$call('GET','/auth/me',$auth,null,[],'192.0.2.81');check($r->status===200,'shared peer/principal budget first request');$r=$call('GET','/projects',$auth,null,[],'::ffff:192.0.2.81');check($r->status===429,'shared budget cannot be bypassed through route or mapped IP');
    $cfg->set('api.rate_limit',600);$cfg->set('api.rate_admin_write',1);$r=$call('POST','/projects',$auth,['name'=>'Second','slug'=>'second']);check($r->status===201,'first administrative write allowed');$r=$call('POST','/projects',$auth,['name'=>'Third','slug'=>'third']);check($r->status===429,'second administrative write rejected');
    echo "Rate limiting PostgreSQL: $n checks passed\n";
} finally {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$base->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');}
