<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{ErrorAnalytics,LogQuery,LogRepository};
use Cajeer\Logs\Database\MigrationSql;
use Cajeer\Logs\Http\HttpException;
require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function verify(bool $v,string $reason):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$reason);++$n;}
$project='11111111-1111-4111-8111-111111111111';
$from='2026-11-17T12:00:00Z';$to='2026-11-18T12:00:00Z';
$statement=MigrationSql::parse(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0031_error_analytics.sql'));
verify($statement['mode']==='transactional' && count($statement['statements'])===2,'migration has a function and a selective index');
verify(str_contains(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0031_error_analytics.sql'),'sha256')&&str_contains(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0031_error_analytics.sql'),'IMMUTABLE'),'fingerprints hashed after normalization');
$q=LogQuery::parse(['project_id'=>$project,'from'=>$from,'to'=>$to,'interval'=>'hour'],'errors');
verify($q->filters['project_id']===$project,'project scoped');
$prev=ErrorAnalytics::previous($q);
verify($prev->filters['to']===$q->filters['from']&&$prev->filters['from']==='2026-11-16T12:00:00.000000Z','equal previous range');
[$sql,$params]=ErrorAnalytics::statement($q);
verify(str_contains($sql,'GROUPING SETS ((),(fingerprint),(bucket))')&&str_contains($sql,'log_error_signature(message,project_id'),'live group and trend SQL');
verify(str_contains($sql,'rn<=100')&&str_contains($sql,'left(sample.message,180)'),'bounded visible group samples');
verify($params['project_id']===$project && isset($params['from'],$params['to']),'scoped placeholders');
$fp=str_repeat('a',64);
$filtered=LogQuery::parse(['project_id'=>$project,'from'=>$from,'to'=>$to,'error_fingerprint'=>$fp],'list');
[$where,$bindings]=LogRepository::predicate($filtered);
verify(str_contains($where,'log_error_signature(')&&$bindings['error_fingerprint']===$fp,'fingerprint drilldown uses the same expression and binding');
foreach (['ab12','A'.str_repeat('a',63),str_repeat('a',65)] as $bad) {
    try { LogQuery::parse(['project_id'=>$project,'from'=>$from,'to'=>$to,'error_fingerprint'=>$bad]); throw new RuntimeException('unsafe fingerprint accepted'); }
    catch (HttpException $e) {verify($e->status===422,'invalid fingerprint rejected');}
}
try { LogQuery::parse(['project_id'=>$project,'error_fingerprint'=>$fp]);throw new RuntimeException('unbounded fingerprint accepted'); }
catch (HttpException $e) {verify($e->status===422,'fingerprint requires time bounds');}
$state=ErrorAnalytics::compare(['groups'=>[['fingerprint'=>$fp,'count'=>4],['fingerprint'=>str_repeat('b',64),'count'=>2]]],
    ['groups'=>[['fingerprint'=>$fp,'count'=>1]],'groups_truncated'=>false]);
verify($state['groups'][0]['classification']==='regressed'&&$state['groups'][0]['change']===3,'regression computed');
verify($state['groups'][1]['classification']==='new'&&$state['groups'][1]['previous_count']===0,'new classification');
$unknown=ErrorAnalytics::compare(['groups'=>[['fingerprint'=>$fp,'count'=>1]]],['groups'=>[],'groups_truncated'=>true]);
verify($unknown['groups'][0]['classification']==='unknown'&&$unknown['groups'][0]['previous_count']===null,'truncation never invents zero');
$csv=ErrorAnalytics::csv(['from'=>$from,'to'=>$to,'total'=>4,'previous_total'=>1,'group_count'=>1,'groups_truncated'=>false,'groups'=>$state['groups'],'buckets'=>[['timestamp'=>$from,'count'=>4]]]);
verify(str_contains($csv,'summary,')&&str_contains($csv,'group,')&&str_contains($csv,'bucket,'),'CSV contains totals groups and trend');
verify(str_contains($csv,'regressed')&&str_contains($csv,'2026-11-17'),'CSV serializes real comparison');
verify(LogQuery::parse(['project_id'=>$project,'from'=>$from,'to'=>$to,'format'=>'csv'],'error_report')->format==='csv','report format validated');
echo "Error analytics unit: $n checks passed\n";
