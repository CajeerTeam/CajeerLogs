<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';
use Cajeer\Logs\Storage\ColdSegment;
$n=0;
$check=static function(bool $ok,string $description) use (&$n): void {
    if (!$ok) throw new RuntimeException('FAIL '.$description);
    ++$n; echo 'PASS '.$description."\n";
};
$reject=static function(callable $callback): bool { try {$callback();} catch (Throwable) {return true;} return false;};
$project='01234567-89ab-4cde-8012-0123456789ab';
$source='01234567-89ab-4cde-8012-0123456789ac';
$id='01234567-89ab-4cde-8012-0123456789ad';
$at='2026-11-15T14:20:00+00:00';
$make=static function(array $row,int $count=1) use ($project): array {
    $plain=''; for ($i=0;$i<$count;$i++) $plain.=json_encode($row,JSON_THROW_ON_ERROR)."\n";
    $payload=gzencode($plain,6);
    return [$payload,['project_id'=>$project,'month_start'=>'2026-11-01',
        'sha256'=>hash('sha256',$payload),'bytes'=>strlen($payload),'event_count'=>$count]];
};
$snapshot=['id'=>$id,'project_id'=>$project,'source_id'=>$source,'received_at'=>$at,'message'=>'persisted'];
$row=['id'=>$id,'project_id'=>$project,'source_id'=>$source,'received_at'=>$at,
    'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR)];
[$data,$meta]=$make($row);
$check(count(ColdSegment::decode($data,$meta,$project))===1,'verified archive record reconstructs successfully');
$check(ColdSegment::sameManifest(['id'=>'one']+$meta,['id'=>'one']+$meta),'same immutable manifest accepted');
$check(!ColdSegment::sameManifest(['id'=>'one']+$meta,['id'=>'two']+$meta),'catalog identity changes rejected');
$bad=$meta; $bad['sha256']=str_repeat('0',64);
$check($reject(fn()=>ColdSegment::decode($data,$bad,$project)),'incorrect SHA-256 rejected');
$bad=$meta; $bad['bytes']+=1;
$check($reject(fn()=>ColdSegment::decode($data,$bad,$project)),'incorrect compressed byte count rejected');
$check($reject(fn()=>ColdSegment::decode($data,$meta,'01234567-89ab-4cde-8012-0123456789aa')),'wrong project rejected');
$bad=$meta; $bad['event_count']=2;
$check($reject(fn()=>ColdSegment::decode($data,$bad,$project)),'incorrect record count rejected');
[$repeated,$mRepeated]=$make($row,2);
$check($reject(fn()=>ColdSegment::decode($repeated,$mRepeated,$project)),'duplicate event identifiers rejected');
$wrong=$row; $wrong['snapshot']=json_encode($snapshot+['additional'=>'value'],JSON_THROW_ON_ERROR);
$wrong['source_id']='01234567-89ab-4cde-8012-0123456789ae';
[$mismatch,$mMismatch]=$make($wrong);
$check($reject(fn()=>ColdSegment::decode($mismatch,$mMismatch,$project)),'record and snapshot source mismatch rejected');
$wrong=$row; $wrong['received_at']='2026-12-15T14:20:00+00:00';
[$outside,$mOutside]=$make($wrong);
$check($reject(fn()=>ColdSegment::decode($outside,$mOutside,$project)),'out-of-month event rejected');
$truncated=substr($data,0,-5);$mTruncated=$meta;$mTruncated['bytes']=strlen($truncated);$mTruncated['sha256']=hash('sha256',$truncated);
$check($reject(fn()=>ColdSegment::decode($truncated,$mTruncated,$project)),'truncated gzip fails even with matching outer SHA-256');
$noNewline=gzencode(substr(json_encode($row,JSON_THROW_ON_ERROR)."\n",0,-1));
$mNoNewline=$meta;$mNoNewline['bytes']=strlen($noNewline);$mNoNewline['sha256']=hash('sha256',$noNewline);
$check($reject(fn()=>ColdSegment::decode($noNewline,$mNoNewline,$project)),'unterminated JSONL record rejected');
echo "Cold segment verification: $n checks passed\n";
