<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Analytics\PostgresRollupStore;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\LogQuery;
use PDO;

/** Operator-only exact rollup backfill, health, reconciliation, and scoped queries. */
final class AnalyticsRollupCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(array $args): int
    {
        $verb=array_shift($args);
        $pdo=$this->app->container()->get(PDO::class);
        $this->app->container()->get(MigrationRunner::class)->assertCurrent();
        $store=new PostgresRollupStore($pdo);
        $result=match($verb) {
            'backfill'=>count($args)>=1 && count($args)<=2
                ?$store->backfill($args[0],self::limit($args[1]??'1000',2000)):self::usage(),
            'status'=>count($args)===1?$store->status($args[0]):self::usage(),
            'verify'=>count($args)===3?$store->verify($args[0],$args[1],$args[2]):self::usage(),
            'aggregate'=>count($args)===3
                ?$store->statistics(LogQuery::parse(['project_id'=>$args[0],'from'=>$args[1],'to'=>$args[2]],'stats')):self::usage(),
            default=>self::usage(),
        };
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 0;
    }

    private static function limit(string $raw,int $max): int
    {
        $limit=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($limit===false) throw new \InvalidArgumentException('Invalid rollup backfill limit.');
        return $limit;
    }

    private static function usage(): never
    {
        throw new \InvalidArgumentException('Usage: analytics:rollups backfill PROJECT_UUID [1..2000] | status PROJECT_UUID | verify PROJECT_UUID FROM_RFC3339 TO_RFC3339 | aggregate PROJECT_UUID FROM_RFC3339 TO_RFC3339');
    }
}
