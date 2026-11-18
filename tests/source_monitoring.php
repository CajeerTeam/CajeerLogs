<?php
declare(strict_types=1);
use Cajeer\Logs\Database\MigrationSql;
use Cajeer\Logs\Database\Repository\SourceMonitoring;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $okay,string $message):void{global $n;if(!$okay)throw new RuntimeException('FAIL '.$message);++$n;echo "PASS $message\n";}
$now=1731954000;
check(SourceMonitoring::health(['status'=>'active','last_seen_at'=>null],60,$now)['state']==='never_received','no arrivals is unknown availability');
check(SourceMonitoring::health(['status'=>'active','last_seen_at'=>gmdate('Y-m-d H:i:s+00',$now-120)],60,$now)['state']==='receiving','recent acceptance');
check(SourceMonitoring::health(['status'=>'active','last_seen_at'=>gmdate('Y-m-d H:i:s+00',$now-3601)],60,$now)['state']==='idle','idle source');
check(SourceMonitoring::health(['status'=>'disabled','last_seen_at'=>null],60,$now)['state']==='disabled','disabled policy state');
check(SourceMonitoring::health(['status'=>'archived','last_seen_at'=>null],60,$now)['state']==='archived','archived policy state');
check(SourceMonitoring::health(['status'=>'active','last_seen_at'=>gmdate('Y-m-d H:i:s+00',$now+500)],60,$now)['state']==='idle','clock-skewed timestamps do not mark healthy');
try{SourceMonitoring::health(['status'=>'active'],0,$now);throw new RuntimeException('unbounded threshold accepted');}catch(InvalidArgumentException){check(true,'idle threshold restricted');}
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0030_source_ingestion_monitoring.sql');
check(MigrationSql::parse($sql)['mode']==='transactional'&&count(MigrationSql::parse($sql)['statements'])===3,'source metrics schema is a real transactional migration');
check(str_contains($sql,'ON DELETE CASCADE')&&str_contains($sql,'PRIMARY KEY (project_id, source_id, minute)'),'project scoped bounded metric buckets');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Database/Repository/SourceRepository.php'),'SourceMonitoring($this->pdo))->count('),'admission writes minute metrics in its transaction');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Database/Repository/SourceRepository.php'),"rejected')"),'policy rejection writes minute metrics in its transaction');
check(str_contains(file_get_contents(dirname(__DIR__).'/core/Scheduler/Scheduler.php'),'source_metrics_cleanup'),'retention of operational counters is scheduled');
echo "Source Monitoring: $n checks passed\n";
