<?php
declare(strict_types=1);
use Cajeer\Logs\Update\AutomationStatus;
use Cajeer\Logs\Http\Request;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
$dir=sys_get_temp_dir().'/cl-auto-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/update-automation.json';
try {
    check(AutomationStatus::read($dir)['status']==='not_configured','missing snapshot is not configured');
    $snapshot=['mode'=>'install','status'=>'downloaded','channel'=>'stable','blocked'=>false,'interval_minutes'=>60,'window_start'=>'03:00','window_minutes'=>30,'candidate'=>'1.1.0','downloaded'=>'1.1.0','last_check'=>time()-60,'next_check'=>time()+3540,'updated_at'=>time(),'remaining_databases'=>2,'units'=>['secret.service'],'databases'=>['private_db'],'notices'=>[['kind'=>'downloaded','version'=>'1.1.0','at'=>time()],['kind'=>'<script>','version'=>'1.1.0','at'=>time()]]];
    file_put_contents($path,json_encode($snapshot));$data=AutomationStatus::read($dir);
    check($data['candidate']==='1.1.0'&&$data['remaining_databases']===2&&!$data['stale'],'valid status projection');
    check(!isset($data['units'],$data['databases'])&&count($data['notices'])===1,'only allowlisted public fields and notices');
    $snapshot['updated_at']=time()-500;file_put_contents($path,json_encode($snapshot));check(AutomationStatus::read($dir)['stale'],'missing heartbeat reported');
    $snapshot['mode']='run shell';file_put_contents($path,json_encode($snapshot));check(AutomationStatus::read($dir)['status']==='not_configured','reject malformed projection');
    unlink($path);symlink('/etc/passwd',$path);check(AutomationStatus::read($dir)['status']==='not_configured','reject symlink');
    check($app->handle(new Request('GET','/api/v1/system/updates',secureTransport:true))->status===401,'automation projection is RBAC protected');
} finally {if(is_file($path)||is_link($path))unlink($path);rmdir($dir);}
echo "Automatic update status: $n checks passed\n";
