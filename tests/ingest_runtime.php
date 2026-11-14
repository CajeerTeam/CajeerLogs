<?php
declare(strict_types=1);
use Cajeer\Logs\Http\{Request,HttpException,ErrorHandler,JsonResponse};
use Cajeer\Logs\Log\{IngestAdmission,IngestBudget,IngestEnvelope,IngestService};
use Cajeer\Logs\Observability\IngestMetrics;
$app=require dirname(__DIR__).'/core/bootstrap.php'; $app->boot();
$count=0;
function check(bool $ok,string $label): void { global $count; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$count; echo "PASS $label\n"; }
function reject(callable $fn,int $status,string $code): bool { try { $fn(); } catch (HttpException $e) { return $e->status===$status&&$e->error===$code; } return false; }
function envelope(array $headers,string $raw='{}',int $limit=1048576): void { IngestEnvelope::validate(new Request('POST','/api/v1/ingest',$headers,[],$raw),$limit); }
foreach (['application/json','application/json; charset=UTF-8','application/vnd.cajeer+json; charset="utf-8"'] as $type) { envelope(['Content-Type'=>$type,'Content-Length'=>'2']); check(true,'valid '.$type); }
foreach ([['Content-Length'=>'1'],['Content-Length'=>'3']] as $headers) check(reject(fn()=>envelope($headers+['Content-Type'=>'application/json']),400,'invalid_body'),'declared length mismatch');
check(reject(fn()=>envelope(['Content-Length'=>'2,2','Content-Type'=>'application/json']),400,'invalid_content_length'),'ambiguous length rejected');
check(reject(fn()=>envelope(['Content-Length'=>'2','Transfer-Encoding'=>'chunked','Content-Type'=>'application/json']),400,'ambiguous_body'),'TE plus CL rejected');
check(reject(fn()=>envelope(['Transfer-Encoding'=>'gzip','Content-Type'=>'application/json']),400,'invalid_transfer_encoding'),'unknown transfer encoding rejected');
envelope(['Transfer-Encoding'=>'chunked','Content-Type'=>'application/json']); check(true,'server-decoded chunked body accepted');
check(reject(fn()=>envelope(['Content-Length'=>'99999999999999999999999999','Content-Type'=>'application/json']),413,'payload_too_large'),'oversized declared body');
check(reject(fn()=>envelope(['Content-Type'=>'application/json'],'{}',1),413,'payload_too_large'),'lower configured body cap');
foreach (['application/json, text/plain','application/json; charset=latin1','foo+json','application/json; charset=utf-8; charset=utf-8'] as $type) check(reject(fn()=>envelope(['Content-Type'=>$type]),415,'unsupported_media_type'),'invalid media '.$type);
check(reject(fn()=>envelope(['Content-Type'=>'application/json','Content-Encoding'=>'gzip']),415,'unsupported_media_type'),'unsupported content encoding');
check(reject(fn()=>envelope(['Content-Type'=>'application/json'],''),400,'invalid_json'),'empty body');
check(reject(fn()=>new Request('POST','/',['Content-Type'=>'application/json','content-type'=>'application/json']),400,'invalid_header'),'duplicate case-insensitive header');
check(reject(fn()=>new Request('POST','/',['Authorization'=>"Bearer x\r\nInjected: true"]),400,'invalid_header'),'header injection rejected');
$savedServer=$_SERVER; $savedGet=$_GET;
try {
    $_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/health','CONTENT_LENGTH'=>'','CONTENT_TYPE'=>'']; $_GET=[];
    check(Request::fromGlobals(1024,'/api/v1')->header('Content-Length')===null,'empty CGI length treated as absent');
    $_SERVER=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v1/ingest','HTTP_CONTENT_LENGTH'=>'2','CONTENT_LENGTH'=>'3'];
    check(reject(fn()=>Request::fromGlobals(1024,'/api/v1'),400,'invalid_header'),'conflicting CGI length rejected');
} finally { $_SERVER=$savedServer; $_GET=$savedGet; }
$budget=new IngestBudget(100); check($budget->remaining()>0,'monotonic budget positive'); usleep(120000);
check(reject(fn()=>$budget->check(),503,'database_unavailable'),'deadline expires before write');
$r=ErrorHandler::response(IngestBudget::unavailable(),'id'); check($r->status===503&&$r->header('Retry-After')==='5'&&json_decode($r->body,true)['error']==='service_unavailable','safe temporary storage response');
$tmp=sys_get_temp_dir().'/cl-ingest-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
try {
    $a=IngestAdmission::acquire($tmp,2); $b=IngestAdmission::acquire($tmp,2);
    check(reject(fn()=>IngestAdmission::acquire($tmp,2),503,'ingest_busy'),'all concurrency slots held');
    // A separate process cannot bypass the shared lock files.
    $root=dirname(__DIR__); $code='require '.var_export($root.'/core/bootstrap.php',true).'; try { \\Cajeer\\Logs\\Log\\IngestAdmission::acquire('.var_export($tmp,true).',2); exit(2); } catch (\\Cajeer\\Logs\\Http\\HttpException $e) { exit($e->error==="ingest_busy"?0:3); }';
    $proc=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); foreach($pipes as $pipe) fclose($pipe); check(proc_close($proc)===0,'cross-process backpressure');
    $a->release(); $c=IngestAdmission::acquire($tmp,2); check(true,'released slot reused'); $c->release(); $b->release();
    $disabled=IngestAdmission::acquire('/not/a/real/path',0); $disabled->release(); check(true,'explicit disable no filesystem required');
    symlink('/dev/null',$tmp.'/ingest-slot-2.lock'); $a=IngestAdmission::acquire($tmp,3);$b=IngestAdmission::acquire($tmp,3);check(reject(fn()=>IngestAdmission::acquire($tmp,3),503,'database_unavailable'),'unsafe slot path rejected');$a->release();$b->release();
    $metrics=new IngestMetrics($tmp); $metrics->record('sync',new JsonResponse(['accepted'=>true],201),0.04);$metrics->record('async',new JsonResponse(['accepted'=>true],202),0.2);$metrics->record('sync',new JsonResponse(['error'=>'forbidden'],403),0.01);$metrics->record('sync',new JsonResponse(['error'=>'SECRET_NEVER_LABEL'],422),0.4);
    $out=(new IngestMetrics($tmp))->render();
    check(str_contains($out,'cajeerlogs_ingest_requests_total{mode="sync",status="201"} 1')&&str_contains($out,'cajeerlogs_ingest_requests_total{mode="async",status="202"} 1'),'persistent per-mode status counters');
    check(str_contains($out,'cajeerlogs_ingest_rejections_total{mode="sync",reason="forbidden"} 1')&&str_contains($out,'reason="other"')&&!str_contains($out,'SECRET_NEVER_LABEL'),'bounded rejection labels');
    check(str_contains($out,'cajeerlogs_ingest_duration_seconds_bucket{mode="sync",le="0.05"} 2')&&str_contains($out,'cajeerlogs_ingest_duration_seconds_count{mode="sync"} 3'),'cumulative latency histogram');
    file_put_contents($tmp.'/ingest-metrics.json','broken'); check(str_contains($metrics->render(),'cajeerlogs_ingest_metrics_available 0'),'corrupt metrics omitted');
} finally { foreach(glob($tmp.'/*') as $file) unlink($file); rmdir($tmp); }
check($app->container()->get(IngestService::class) instanceof IngestService,'shared service registered');
$app->config()->set('app.env','test'); $app->config()->set('database.default','unsupported');
foreach (['/ingest','/ingest/async'] as $path) {
    $headers=['Authorization'=>'Bearer cl_'.str_repeat('a',64),'Content-Type'=>'text/plain','Idempotency-Key'=>'new'];
    $r=$app->handle(new Request('POST','/api/v1'.$path,$headers,[],'{}'));check($r->status===415,'envelope rejected before database '.$path);
    $headers['Content-Type']='application/json';$r=$app->handle(new Request('POST','/api/v1'.$path,$headers,[],'{}'));check($r->status===503&&$r->header('Retry-After')==='5','database failure retry guidance '.$path);
}
echo "Ingest Runtime: $count checks passed\n";
