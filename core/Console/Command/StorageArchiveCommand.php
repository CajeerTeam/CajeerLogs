<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Storage\{ArchiveLifecycle,S3CompatibleDisk};
use PDO;

/** Operator-only storage lifecycle. Destructive transitions always require --confirm. */
final class StorageArchiveCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(array $args): int
    {
        $verb=array_shift($args);
        $this->app->container()->get(MigrationRunner::class)->assertCurrent();
        $needsS3=in_array($verb,['cold','restore-cold','purge-cold','verify-cold','scrub-cold'],true);
        $storage=new ArchiveLifecycle($this->app->container()->get(PDO::class),$needsS3?new S3CompatibleDisk():null);
        $result=match($verb) {
            'status'=>count($args)===1?$storage->status($args[0]):self::usage(),
            'cold-list'=>count($args)===1?$storage->coldObjects($args[0]):self::usage(),
            'verify-cold'=>count($args)===2?$storage->verifyCold($args[0],$args[1]):self::usage(),
            'scrub-cold'=>count($args)>=1 && count($args)<=2?$storage->scrubCold($args[0],self::batch($args[1]??'25')):self::usage(),
            'stage'=>count($args)>=2 && count($args)<=3?$storage->stage($args[0],$args[1],self::batch($args[2]??'100')):self::usage(),
            'evict'=>count($args)>=3 && count($args)<=4 && end($args)==='--confirm'
                ?$storage->evict($args[0],$args[1],self::batch(count($args)===4?$args[2]:'100')):self::usage(),
            'restore-hot'=>count($args)>=2 && count($args)<=3?$storage->restoreHot($args[0],$args[1],self::batch($args[2]??'100')):self::usage(),
            'cold'=>count($args)>=3 && count($args)<=4 && end($args)==='--confirm'
                ?$storage->offload($args[0],$args[1],self::batch(count($args)===4?$args[2]:'100')):self::usage(),
            'restore-cold'=>count($args)===2?$storage->restoreCold($args[0],$args[1]):self::usage(),
            'purge-warm'=>count($args)>=3 && count($args)<=4 && end($args)==='--confirm'
                ?$storage->purgeWarm($args[0],$args[1],self::batch(count($args)===4?$args[2]:'500')):self::usage(),
            'purge-cold'=>count($args)===3 && $args[2]==='--confirm'?$storage->purgeCold($args[0],$args[1]):self::usage(),
            default=>self::usage(),
        };
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 0;
    }
    private static function batch(string $raw): int { return ArchiveLifecycle::limit($raw); }
    private static function usage(): never
    {
        throw new \InvalidArgumentException('Usage: storage:archive stage PROJECT YYYY-MM [1..500] | evict PROJECT YYYY-MM [1..500] --confirm | restore-hot PROJECT YYYY-MM [1..500] | cold PROJECT YYYY-MM [1..500] --confirm | cold-list PROJECT | verify-cold PROJECT SEGMENT_UUID | scrub-cold PROJECT [1..100] | restore-cold PROJECT SEGMENT_UUID | purge-warm PROJECT YYYY-MM [1..500] --confirm | purge-cold PROJECT SEGMENT_UUID --confirm | status PROJECT');
    }
}
