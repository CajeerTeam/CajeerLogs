<?php
declare(strict_types=1);
/** Structural safety check; real PostgreSQL multi-page assertion is in storage_tiers_database.php. */
require dirname(__DIR__).'/core/bootstrap.php';
use Cajeer\Logs\Storage\ArchiveLifecycle;
$method=new ReflectionMethod(ArchiveLifecycle::class,'restoreHot');
$source=file_get_contents((new ReflectionClass(ArchiveLifecycle::class))->getFileName());
$start=strpos($source,'public function restoreHot(');
$end=strpos($source,'private function remote()', $start);
if ($start===false || $end===false) throw new RuntimeException('Missing production restore path');
$sql=substr($source,$start,$end-$start);
if (!preg_match('/NOT EXISTS\s*\(SELECT 1 FROM log_events l WHERE l\.id=a\.id\)\s*ORDER BY a\.received_at,a\.id LIMIT :limit/s',$sql)) {
    throw new RuntimeException('Restore paging would starve behind existing hot rows');
}
if (ArchiveLifecycle::month('2026-11')[2]!=='202611') throw new RuntimeException('Unexpected UTC month');
echo "PASS real restore query excludes already restored rows before LIMIT\n";
