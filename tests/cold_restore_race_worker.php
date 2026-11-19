<?php
declare(strict_types=1);
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Storage\{ArchiveLifecycle,S3CompatibleDisk};
if (getenv('CAJEER_TEST_DATABASE')!=='1' || getenv('APP_ENV')!=='test'
    || count($argv)!==5 || !preg_match('/^cl_storage_[a-f0-9]{12}$/D',$argv[1])) exit(78);
require dirname(__DIR__).'/core/bootstrap.php';
$app=new Application(dirname(__DIR__));$app->boot();
$app->config()->set('database.schema',$argv[1]);
$s3=new S3CompatibleDisk(['endpoint'=>'http://127.0.0.1:'.$argv[2],'bucket'=>'cajeer-test',
    'access_key'=>'TESTACCESS','secret_key'=>'secret-test-signing-key',
    'region'=>'us-east-1','path_style'=>true,'allow_insecure'=>true]);
$archive=new ArchiveLifecycle($app->container()->get(PDO::class),$s3);
try {
    $archive->restoreCold($argv[3],$argv[4]);
    fwrite(STDERR,"FAIL restored a segment that was concurrently purged.\n");
    exit(2);
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'catalog changed during download')) {
        fwrite(STDERR,"FAIL unexpected rejection: ".$e->getMessage()."\n");
        exit(3);
    }
    exit(0);
}
