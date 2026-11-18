<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Analytics\ClickHouseAnalyticsStore;
use Cajeer\Logs\Analytics\ClickHouseMigrator;
use Cajeer\Logs\Kernel\Application;

/** Operator-only ClickHouse lifecycle (no public/admin endpoints). */
final class ClickHouseCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(array $args): int
    {
        $verb=array_shift($args);
        $client=$this->app->container()->get(\Cajeer\Logs\Analytics\ClickHouseHttpClient::class);
        $store=new ClickHouseAnalyticsStore($client);
        $result=match($verb) {
            'status'=>$args===[]?$store->ping():self::usage(),
            'migrate'=>$args===['--confirm']?(new ClickHouseMigrator($client,$this->app->basePath('migrations/clickhouse')))->migrate():self::usage(),
            'aggregate'=>$this->aggregate($store,$args),
            'record'=>$this->record($store,$args),
            default=>self::usage(),
        };
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).'\n';
        return 0;
    }

    private function aggregate(ClickHouseAnalyticsStore $store,array $args): array
    {
        if (count($args)!==1) self::usage();
        $filters=self::readJson($args[0]);
        return $store->aggregate($filters);
    }

    private function record(ClickHouseAnalyticsStore $store,array $args): array
    {
        if (count($args)!==2 || $args[0]!=='--confirm') self::usage();
        $events=self::readJson($args[1]);
        if (array_is_list($events)) $store->recordBatch($events);
        else $store->record($events);
        return ['recorded'=>array_is_list($events)?count($events):1];
    }

    private static function readJson(string $name): array
    {
        if (!str_starts_with($name,'/') || !is_file($name) || is_link($name) || !is_readable($name) || filesize($name)>1048576) {
            throw new \InvalidArgumentException('Expected a readable absolute, regular JSON file <=1 MiB.');
        }
        $text=file_get_contents($name);
        if ($text===false) throw new \RuntimeException('Cannot read JSON file.');
        $value=json_decode($text,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value) || $value===[]) throw new \InvalidArgumentException('Expected a nonempty JSON object or array.');
        return $value;
    }

    private static function usage(): never
    {
        throw new \InvalidArgumentException('Usage: analytics:clickhouse status | migrate --confirm | aggregate /absolute/filters.json | record --confirm /absolute/events.json');
    }
}
