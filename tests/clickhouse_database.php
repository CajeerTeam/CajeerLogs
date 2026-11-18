<?php
declare(strict_types=1);

use Cajeer\Logs\Analytics\{ClickHouseHttpClient,ClickHouseAnalyticsStore,ClickHouseMigrator};

$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
if (getenv('CAJEER_TEST_CLICKHOUSE')!=='1') { fwrite(STDERR,"Set CAJEER_TEST_CLICKHOUSE=1 for disposable real ClickHouse integration.\n"); exit(78); }
$client=$app->container()->get(ClickHouseHttpClient::class);
$store=new ClickHouseAnalyticsStore($client);
$n=0;
function check(bool $pass,string $name):void {global $n;if (!$pass) throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}
function rejected(callable $f):bool {try{$f();}catch(InvalidArgumentException $e){return true;}return false;}
$migrator=new ClickHouseMigrator($client,dirname(__DIR__).'/migrations/clickhouse');
$first=$migrator->migrate();$second=$migrator->migrate();
check(count($second['skipped'])===3 && $second['applied']===[],'repeatable migrations with SHA-256 history');
check(($store->ping()['version']??'')!=='','real ClickHouse HTTP connectivity');
$id=static fn()=>sprintf('%08x-%04x-4%03x-a%03x-%012x',random_int(1,2147483647),random_int(0,65535),random_int(0,4095),random_int(0,4095),random_int(1,2147483647));
$a=$id();$b=$id();$source=$id();$when=gmdate('Y-m-d\TH:i:s\Z');
$base=['source_id'=>$source,'occurred_at'=>$when,'received_at'=>$when,'level'=>'error','source'=>'integration-ci','service'=>'testing','host'=>'ci-host','message'=>'ClickHouse integration','context'=>['ci'=>true],'tags'=>['ci']];
$store->recordBatch([['id'=>$id(),'project_id'=>$a]+$base,['id'=>$id(),'project_id'=>$b]+$base]);
// ClickHouse INSERT visibility is synchronous for default settings; no arbitrary sleeps.
$result=$store->aggregate(['project_id'=>$a,'from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60),'interval'=>'minute']);
check($result['total']===1 && $result['by_level']['error']===1,'project A only; live HTTP INSERT/SELECT');
check(count($result['by_source'])===1 && $result['by_source'][0]['source_id']===$source,'source identifier and scope');
check(count($result['buckets'])>=1 && $result['buckets'][0]['errors']===1,'real grouped timeline');
$other=$store->aggregate(['project_id'=>$b,'from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)]);
check($other['total']===1,'project B does not see A');
check(rejected(fn()=>$store->aggregate(['from'=>$when,'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)])),'deny unscoped queries');
$injection=$store->aggregate(['project_id'=>$a,'service'=>"x' OR 1=1 --",'from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)]);
check($injection['total']===0,'SQL metacharacters are bound as values');
check(rejected(fn()=>$store->record(['id'=>$id(),'project_id'=>$a,'source_id'=>$source,'occurred_at'=>$when,'received_at'=>$when,'level'=>'untrusted'])),'reject invalid event level');
echo "ClickHouse database checks: $n passed\n";
