<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';
use Cajeer\Logs\Storage\S3CompatibleDisk;
$port=getenv('S3_TEST_PORT');$file=getenv('S3_TEST_FAULT_FILE');
if (!$port || !$file || !str_starts_with($file,sys_get_temp_dir().'/')) {
    fwrite(STDERR,"Requires isolated signed S3 test server and private fault control.\n"); exit(78);
}
$client=new S3CompatibleDisk(['endpoint'=>'http://127.0.0.1:'.$port,'bucket'=>'cajeer-test',
    'access_key'=>'TESTACCESS','secret_key'=>'secret-test-signing-key','region'=>'us-east-1',
    'path_style'=>true,'allow_insecure'=>true]);
$key='cajeerlogs/archive/v1/test/fault-'.bin2hex(random_bytes(8)).'.bin';
$body=random_bytes(51200);$digest=hash('sha256',$body);
$n=0;
$check=static function(bool $ok,string $desc)use(&$n):void {if(!$ok)throw new RuntimeException('FAIL '.$desc);++$n;echo "PASS $desc\n";};
$reject=static function(callable $op):bool {try{$op();}catch(RuntimeException){return true;}return false;};
$arm=static function(string $mode)use($file):void {
    if (file_put_contents($file,$mode,LOCK_EX)===false)throw new RuntimeException('Cannot arm the next signed HTTP fault.');
};
try {
    $arm('put_503');$check($reject(fn()=>$client->putVerified($key,$body)),'503 PUT rejects upload');
    $arm('put_lost_ack');$check($reject(fn()=>$client->putVerified($key,$body)),
        'ambiguous successful PUT with lost response is not acknowledged');
    $check($client->getVerified($key,$digest)===$body,'committed lost-ACK object can be independently verified');
    $check($client->putVerified($key,$body)['sha256']===$digest,'repeating identical immutable object is safe');
    $arm('get_503');$check($reject(fn()=>$client->getVerified($key,$digest)),'GET 503 cannot be mistaken for contents');
    $arm('get_corrupt');$check($reject(fn()=>$client->getVerified($key,$digest)),'corrupted GET response is rejected by SHA-256');
    $check($client->getVerified($key,$digest)===$body,'readback after transient failures preserves exact bytes');
    $arm('delete_503');$check($reject(fn()=>$client->delete($key)),'DELETE 503 cannot claim object erasure');
    $check($client->getVerified($key,$digest)===$body,'failed DELETE leaves the object available');
    $client->delete($key);$check($reject(fn()=>$client->getVerified($key,$digest)),'successful DELETE removes object');
    echo "S3 fault-injection HTTP: $n checks passed\n";
} finally {
    if(is_file($file))unlink($file);
}
