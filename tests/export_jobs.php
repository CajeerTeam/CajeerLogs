<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{ExportQueue,LogExport};
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $description):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$description);$n++;echo "PASS $description\n";}
function rejected(callable $fn,int $expected):bool{try{$fn();}catch(HttpException $e){return $e->status===$expected;}return false;}
$id='11111111-1111-4111-8111-111111111111';
$range=['from'=>'2026-11-01T00:00:00Z','to'=>'2026-11-03T00:00:00Z'];
[$f,$format]=ExportQueue::filterInput(['format'=>'json','filters'=>$range],$id);
check($format==='json' && isset($f['from'],$f['to']) && $f['project_id']===$id,'canonical project-bound export filters');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'csv','filters'=>['project_id'=>$id]+$range],$id),422),'reject client project override');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'csv','filters'=>['cursor'=>'abc']+$range],$id),422),'reject client cursor');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'xml','filters'=>$range],$id),422),'reject unknown format');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'csv','filters'=>['from'=>'2026-01-01T00:00:00Z','to'=>'2026-11-03T00:00:00Z']],$id),422),'reject unbounded export range');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'json','filters'=>['source_id'=>'bad']+$range],$id),422),'reject malformed scoped source');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'json','filters'=>['limit'=>'100']+$range],$id),422),'reject pagination override');
check(rejected(fn()=>ExportQueue::filterInput(['format'=>'csv','filters'=>['unknown'=>'x']+$range],$id),422),'reject unknown filter keys');
$event=['id'=>'abc','message'=>'=cmd|\' /C calc','context'=>(object)['formula'=>'=SUM(1,1)'],'tags'=>['a','b']];
$csv=LogExport::csv([$event]);
check(str_contains($csv,"'=cmd") && str_contains($csv,'SUM(1,1)'),'CSV neutralizes formula cells');
$fp=fopen('php://temp','w+b');LogExport::csvHeader($fp);LogExport::csvRow($fp,$event);rewind($fp);$same=stream_get_contents($fp);fclose($fp);
check($same===$csv,'streaming and one-page CSV are identical');
$fake=tmpfile();fwrite($fake,'payload');$stat=fstat($fake);$path=stream_get_meta_data($fake)['uri'];
$r=new \Cajeer\Logs\Http\FileDownloadResponse($path,'cajeerlogs-'.$id.'.csv','csv',7);
check($r->header('Content-Length')==='7'&&str_contains($r->header('Content-Disposition'),$id),'download response advertises bounded file length');fclose($fake);
echo "Bulk export unit tests: $n passed\n";
