<?php
declare(strict_types=1);
use Cajeer\Logs\Analytics\ClickHouseMigrator;
use Cajeer\Logs\Database\DatabaseException;
$root=dirname(__DIR__);
require $root.'/core/bootstrap.php';
$n=0;
function certCheck(bool $ok,string $label):void {global $n;if (!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function certReject(callable $call):bool {try {$call();}catch(\RuntimeException $error) {return true;} return false;}
$directory=$root.'/migrations/clickhouse';
$manifest=ClickHouseMigrator::manifest($directory);
certCheck(count($manifest)===3,'all three ClickHouse migrations prevalidated');
$names=array_keys($manifest);
$all=[];foreach($manifest as $name=>$file)$all[]=['version'=>$name,'checksum'=>$file['checksum']];
certCheck(count(ClickHouseMigrator::validateHistory($manifest,$all))===3,'complete ordered history accepted');
certCheck(ClickHouseMigrator::validateHistory($manifest,[])===[],'empty history accepted before first migration');
certCheck(count(ClickHouseMigrator::validateHistory($manifest,array_slice($all,0,1)))===1,'applied prefix accepted');
certCheck(certReject(fn()=>ClickHouseMigrator::validateHistory($manifest,[['version'=>'9999_future.sql','checksum'=>str_repeat('a',64)]])),'future unknown migration rejected');
certCheck(certReject(fn()=>ClickHouseMigrator::validateHistory($manifest,[$all[0],$all[2]])),'gapped migration history rejected');
certCheck(certReject(fn()=>ClickHouseMigrator::validateHistory($manifest,[$all[0],$all[0]])),'duplicate migration history rejected');
certCheck(certReject(fn()=>ClickHouseMigrator::validateHistory($manifest,[['version'=>$all[0]['version'],'checksum'=>str_repeat('0',64)]])),'modified migration checksum rejected');
$tmp=sys_get_temp_dir().'/cl_cert_manifest_'.bin2hex(random_bytes(5));
mkdir($tmp,0700);
try {
    copy($directory.'/'.$names[0],$tmp.'/'.$names[0]);
    copy($directory.'/'.$names[2],$tmp.'/'.$names[2]);
    certCheck(certReject(fn()=>ClickHouseMigrator::manifest($tmp)),'gap in local migration files rejected before DDL');
    unlink($tmp.'/'.$names[2]);
    file_put_contents($tmp.'/0002_empty.sql','');
    certCheck(certReject(fn()=>ClickHouseMigrator::manifest($tmp)),'empty SQL migration rejected before DDL');
    unlink($tmp.'/0002_empty.sql');
    symlink($directory.'/'.$names[1],$tmp.'/'.$names[1]);
    certCheck(certReject(fn()=>ClickHouseMigrator::manifest($tmp)),'symlinked SQL migration rejected before DDL');
} finally {foreach (glob($tmp.'/*')?:[] as $path)unlink($path);rmdir($tmp);}
echo "Migration certification unit: $n checks passed\n";
