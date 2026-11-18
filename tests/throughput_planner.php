<?php
declare(strict_types=1);
use Cajeer\Logs\Queue\{PostgresQueueDriver,WorkerPolicy};
require dirname(__DIR__).'/core/bootstrap.php';
$passed=0;
function proof(bool $condition,string $message): void {global $passed;if (!$condition) throw new RuntimeException('FAIL: '.$message);$passed++;echo "PASS $message\n";}
function rejects(callable $fn): bool {try{$fn();}catch(InvalidArgumentException){return true;}return false;}
$first=['payload_events'=>40,'payload_bytes'=>1000,'validation_version'=>'2'];
$candidates=[
 ['id'=>'10','payload_events'=>61,'payload_bytes'=>500,'validation_version'=>'2'], // too many events
 ['id'=>'11','payload_events'=>20,'payload_bytes'=>500,'validation_version'=>'2'],
 ['id'=>'12','payload_events'=>1,'payload_bytes'=>500,'validation_version'=>'1'], // incompatible version
 ['id'=>'13','payload_events'=>0,'payload_bytes'=>100,'validation_version'=>'2'], // corrupt
 ['id'=>'14','payload_events'=>39,'payload_bytes'=>500,'validation_version'=>'2'],
 ['id'=>'15','payload_events'=>2,'payload_bytes'=>500,'validation_version'=>'2'], // 101 total
 ['id'=>'16','payload_events'=>1,'payload_bytes'=>500,'validation_version'=>'2'],
];
proof(PostgresQueueDriver::selectCompatible($first,$candidates,10)===['11','14','16'],'skips overfull, corrupt and different-version jobs and preserves order');
proof(PostgresQueueDriver::selectCompatible($first,$candidates,1)===['11'],'bounded group size honored');
proof(PostgresQueueDriver::selectCompatible($first,$candidates,0)===[],'no additional capacity');
proof(PostgresQueueDriver::selectCompatible(['payload_events'=>100,'payload_bytes'=>1000,'validation_version'=>'2'],$candidates,5)===[],'full 100-event job remains isolated');
proof(PostgresQueueDriver::selectCompatible(['payload_events'=>1,'payload_bytes'=>4194303,'validation_version'=>'2'],$candidates,5)===[],'conservative persisted JSONB byte limit');
proof(PostgresQueueDriver::selectCompatible(['payload_events'=>1,'payload_bytes'=>4194000,'validation_version'=>'2'],[['id'=>'17','payload_events'=>1,'payload_bytes'=>300,'validation_version'=>'2']],5)===['17'],'exact byte boundary accepted');
proof(PostgresQueueDriver::selectCompatible(['payload_events'=>1,'payload_bytes'=>4194000,'validation_version'=>'2'],[['id'=>'17','payload_events'=>1,'payload_bytes'=>305,'validation_version'=>'2']],5)===[],'one-byte overflow rejected without a lease');
proof(PostgresQueueDriver::selectCompatible(['payload_events'=>0,'payload_bytes'=>100,'validation_version'=>'2'],$candidates,5)===[],'corrupted first job cannot poison batch');
proof(rejects(fn()=>PostgresQueueDriver::selectCompatible($first,$candidates,16)),'cannot select more than fifteen additional leases');
proof((new WorkerPolicy())->get('batch_jobs')===4,'default worker uses optimized grouping');
proof((new WorkerPolicy(['batch_jobs'=>1]))->get('batch_jobs')===1,'rollback to per-job processing remains available');
echo "Throughput planner: $passed checks passed\n";
