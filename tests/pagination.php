<?php
declare(strict_types=1);
use Cajeer\Logs\Log\{LogQuery,LogRepository,PageCursor,LogExport};
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;
function check(bool $ok,string $name):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$name);++$n;echo "PASS $name\n";}
function fail(callable $f,int $status=422):bool {try{$f();}catch(HttpException $e){return $e->status===$status;}return false;}
$p='11111111-1111-4111-8111-111111111111';$s='22222222-2222-4222-8222-222222222222';$other='33333333-3333-4333-8333-333333333333';
$ids=['aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa','bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb','cccccccc-cccc-4ccc-cccc-cccccccccccc','dddddddd-dddd-4ddd-dddd-dddddddddddd'];
$t='2026-01-01T00:00:00.123456Z';$watermark='2026-11-15T00:00:00.123456Z';$base=['project_id'=>$p,'limit'=>'2'];$q=LogQuery::parse($base);$signer=new PageCursor(str_repeat('a',32),'token:'.$s);$now=1794708000;
$state=$signer->open($q,$now);check($state===['d'=>'older','t'=>null,'id'=>null,'w'=>null,'exp'=>$now+3600],'initial live state');
$cursor=$signer->issue($q,array_replace($state,['t'=>$t,'id'=>$ids[2]]));
$parsed=LogQuery::parse($base+['cursor'=>$cursor]);$opened=$signer->open($parsed,$now);
check($opened['id']===$ids[2]&&$opened['t']===$t&&$opened['d']==='older','authenticated roundtrip');
check($signer->open(LogQuery::parse(array_replace($base,['limit'=>'1'])+['cursor'=>$cursor]),$now)===$opened,'variable page size');
foreach ([$cursor.'x',substr($cursor,0,-1).($cursor[-1]==='A'?'B':'A'),$q->next($t,$ids[2]),'invalid','a.b.c','a.!!!!'] as $bad) check(fail(fn()=>$signer->open(LogQuery::parse($base+['cursor'=>$bad]),$now)),'reject tampered or legacy cursor');
check(fail(fn()=>(new PageCursor(str_repeat('b',32),'token:'.$s))->open($parsed,$now)),'wrong server signing key');
check(fail(fn()=>(new PageCursor(str_repeat('a',32),'token:'.$other))->open($parsed,$now)),'different authenticated principal');
foreach ([['project_id'=>$other],['source_id'=>$s],['q'=>'other'],['consistency'=>'snapshot']] as $changes) check(fail(fn()=>$signer->open(LogQuery::parse(array_replace($base,$changes)+['cursor'=>$cursor]),$now)),'changed scope/filter/mode');
check(fail(fn()=>$signer->open($parsed,$now+3600)),'expired cursor rejected');
check(fail(fn()=>LogQuery::parse($base+['consistency'=>'invalid'])),'invalid consistency');
check(fail(fn()=>LogQuery::parse($base+['consistency'=>'snapshot'],'stats')),'statistics independent of snapshot');
$snapshot=LogQuery::parse($base+['consistency'=>'snapshot']);$ss=$signer->open($snapshot,$now);$ss['w']=$watermark;
$firstCursor=$signer->issue($snapshot,$ss);check($signer->open(LogQuery::parse($base+['consistency'=>'snapshot','cursor'=>$firstCursor]),$now)===$ss,'signed first snapshot page preserves watermark');
$ordered=LogQuery::parse($base+['levels'=>'["warning","error"]']);$c=$signer->issue($ordered,$state);
check($signer->open(LogQuery::parse($base+['levels'=>'["error","warning"]','cursor'=>$c]),$now)===$state,'canonical list order binding');
check(fail(fn()=>$signer->open(LogQuery::parse($base+['cursor'=>$signer->issue($q,array_replace($state,['d'=>'newer']))]),$now)),'reverse cursor requires anchor');
check(fail(fn()=>$signer->open(LogQuery::parse($base+['cursor'=>$signer->issue($q,array_replace($state,['w'=>$watermark]))]),$now)),'live cursor cannot carry watermark');
[$sql,$params]=LogRepository::explorerStatement($snapshot,array_replace($ss,['d'=>'newer','t'=>$t,'id'=>$ids[1]]));
check(str_contains($sql,'(occurred_at,id) > (CAST(:cursor_time AS timestamptz),CAST(:cursor_id AS uuid))')&&str_contains($sql,'ORDER BY occurred_at ASC,id ASC LIMIT 3'),'reverse keyset reads nearest newer rows');
check(str_contains($sql,'received_at <= CAST(:watermark AS timestamptz)')&&$params['watermark']===$watermark&&$params['project_id']===$p&&$params['cursor_id']===$ids[1],'snapshot boundary is bound SQL value');
check(str_contains($sql,'sum(octet_length(payload))')&&str_contains($sql,'bytes<=2097152')&&str_contains($sql,'CASE WHEN rn<=2'),'database wire payload bounded before fetch');
[$sql,$params]=LogRepository::explorerStatement($q,$opened);
check(str_contains($sql,'(occurred_at,id) <')&&str_contains($sql,'ORDER BY occurred_at DESC,id DESC LIMIT 3')&&!isset($params['watermark']),'forward live keyset');
$row=static fn(string $id,string $msg='event')=>['id'=>$id,'timestamp'=>$t,'payload'=>json_encode(['id'=>$id,'timestamp'=>$t,'project_id'=>$p,'source_id'=>$s,'context'=>(object)['value'=>'x'],'tags'=>[],'message'=>$msg],JSON_THROW_ON_ERROR)];
$overflow=static fn(string $id)=>['id'=>$id,'timestamp'=>$t,'payload'=>null];
$one=LogRepository::explorerPage($snapshot,$signer,$ss,[$row($ids[3]),$row($ids[2]),$overflow($ids[1])]);
check(array_column($one['items'],'id')===[$ids[3],$ids[2]]&&$one['prev_cursor']===null&&$one['next_cursor']!==null&&$one['snapshot_at']===$watermark,'first page sentinel and metadata');
$nextState=$signer->open(LogQuery::parse($base+['consistency'=>'snapshot','cursor'=>$one['next_cursor']]),$now);
check($nextState['id']===$ids[2]&&$nextState['w']===$watermark&&$nextState['exp']===$ss['exp'],'next cursor keeps cutoff and original expiry');
$two=LogRepository::explorerPage($snapshot,$signer,$nextState,[$row($ids[1]),$row($ids[0])]);
check($two['next_cursor']===null&&$two['prev_cursor']!==null&&array_column($two['items'],'id')===[$ids[1],$ids[0]],'last older page has newer continuation');
$backState=$signer->open(LogQuery::parse($base+['consistency'=>'snapshot','cursor'=>$two['prev_cursor']]),$now);
$back=LogRepository::explorerPage($snapshot,$signer,$backState,[$row($ids[2]),$row($ids[3])]);
check($backState['d']==='newer'&&$backState['id']===$ids[1]&&array_column($back['items'],'id')===array_column($one['items'],'id')&&$back['prev_cursor']===null,'reverse scan restores descending order');
check($back['page_digest']===$one['page_digest'],'same displayed content has same digest across directions');
$changed=LogRepository::explorerPage($snapshot,$signer,$ss,[$row($ids[3],'changed'),$row($ids[2]),$overflow($ids[1])]);
check($changed['page_digest']!==$one['page_digest'],'page digest detects changed event');
$short=LogRepository::explorerPage($q,$signer,$state,[$row($ids[3]),$overflow($ids[2])]);
check(count($short['items'])===1&&$short['next_cursor']!==null,'byte-limited short page continues from last delivered event');
check(fail(fn()=>LogRepository::explorerPage($q,$signer,$state,[$overflow($ids[3])]),413),'oversize first event fails instead of skipping');
$empty=LogRepository::explorerPage($q,$signer,$opened,[]);check($empty['items']===[]&&$empty['prev_cursor']===null&&$empty['next_cursor']===null&&$empty['page_cursor']!==null,'empty changed page can restart');
$large=LogRepository::explorerPage($q,$signer,$state,[$row($ids[3],str_repeat('x',1100000)),$row($ids[2],str_repeat('y',1100000))]);
check(count($large['items'])===1&&$large['next_cursor']!==null,'PHP serialization budget independently enforced');
check(str_contains(LogExport::csv($one['items']),$ids[3]),'CSV exports actual page rows');
check($signer->open(LogQuery::parse($base+['consistency'=>'snapshot','format'=>'csv','cursor'=>$one['page_cursor']],'export'),$now)===$ss,'current signed page cursor works across export format');
// A database-only fallback key is generated once and shared by all processes.
final class CursorStmt extends PDOStatement {public function __construct(private CursorPDO $db,private bool $insert=false){} public function execute(?array $params=null):bool {if($this->insert)$this->db->secret??=$params['secret'];return true;}public function fetchColumn(int $column=0):mixed{return $this->db->secret??false;}public function closeCursor():bool{return true;}}
final class CursorPDO extends PDO {public ?string $secret=null;public int $inserts=0;public function __construct(){}public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new CursorStmt($this);}public function prepare(string $query,array $options=[]):PDOStatement|false{++$this->inserts;return new CursorStmt($this,true);}}
$db=new CursorPDO();$principal=['kind'=>'token','id'=>$s];$a=PageCursor::forPrincipal($db,'',$principal);$b=PageCursor::forPrincipal($db,'',$principal);
check($db->inserts===1&&strlen($db->secret)===64&&$a->issue($q,$state)===$b->issue($q,$state),'persistent server-generated signing key');
$sessionA=PageCursor::forPrincipal($db,'',['kind'=>'session','id'=>$s,'hash'=>'a']);$sessionB=PageCursor::forPrincipal($db,'',['kind'=>'session','id'=>$s,'hash'=>'b']);
$sc=$sessionA->issue($q,$state);check(fail(fn()=>$sessionB->open(LogQuery::parse($base+['cursor'=>$sc]),$now)),'session-specific cursor binding');
echo "Pagination: $n checks passed\n";
