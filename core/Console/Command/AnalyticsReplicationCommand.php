<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Analytics\{AnalyticsReplicator,ClickHouseAnalyticsStore,ClickHouseHttpClient};
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\LogQuery;
use PDO;

/** Operator-only work, replay, backfill, diagnostics; no user-provided SQL. */
final class AnalyticsReplicationCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(array $args): int
    {
        $verb=array_shift($args);
        if (!in_array($verb,['status','backfill','replay','work','drain','aggregate','compare'],true)) self::usage();
        $pdo=$this->app->container()->get(PDO::class);
        // Do not accidentally write to a database with unapplied trigger migration.
        $this->app->container()->get(MigrationRunner::class)->assertCurrent();
        $client=in_array($verb,['work','drain','aggregate','compare'],true)
            ?$this->app->container()->get(ClickHouseHttpClient::class):null;
        $replica=new AnalyticsReplicator($pdo,$client);
        $result=match($verb) {
            'status'=>$args===[]?$replica->status():self::usage(),
            'work'=>count($args)<=1?$replica->work(self::limit($args[0]??'25',100)):self::usage(),
            'drain'=>count($args)<=2?$this->drain($replica,self::limit($args[0]??'100',1000),self::limit($args[1]??'25',100)):self::usage(),
            'backfill'=>count($args)>=1 && count($args)<=2
                ?$replica->backfill($args[0],self::limit($args[1]??'1000',2000)):self::usage(),
            'replay'=>count($args)>=2 && count($args)<=3 && in_array($args[1],['dead','completed'],true)
                ?['requeued'=>$replica->replay($args[0],self::limit($args[2]??'100',1000),$args[1])]:self::usage(),
            'aggregate'=>count($args)===1?(new ClickHouseAnalyticsStore($client,true))->aggregate(self::readFilters($args[0])):self::usage(),
            'compare'=>count($args)===1?$this->compare($pdo,$client,self::readFilters($args[0])):self::usage(),
            default=>self::usage(),
        };
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 0;
    }

    private function drain(AnalyticsReplicator $replica,int $cycles,int $batch): array
    {
        $totals=['cycles'=>0,'claimed'=>0,'delivered'=>0,'retry'=>0,'dead'=>0,'stale'=>0];
        for ($i=0;$i<$cycles;$i++) {
            $result=$replica->work($batch);
            $totals['cycles']++;
            foreach ($result as $name=>$value) $totals[$name]+=$value;
            if ($result['claimed']===0 || $result['retry']>0 || $result['dead']>0) break;
        }
        return $totals;
    }

    private function compare(PDO $pdo,ClickHouseHttpClient $client,array $filters): array
    {
        if (count(array_diff(array_keys($filters),['project_id','from','to','interval']))>0) {
            throw new \InvalidArgumentException('Compare accepts project_id/from/to/interval only.');
        }
        $aggregate=(new ClickHouseAnalyticsStore($client,true))->aggregate($filters);
        $project=LogQuery::uuid($filters['project_id']??'');
        $stmt=$pdo->prepare('SELECT count(*) FROM log_events WHERE project_id=CAST(:project AS uuid)
            AND occurred_at>=CAST(:from AS timestamptz) AND occurred_at<CAST(:to AS timestamptz)');
        $stmt->execute(['project'=>$project,'from'=>$aggregate['from'],'to'=>$aggregate['to']]);
        $count=(int)$stmt->fetchColumn();
        return ['postgres'=>$count,'clickhouse'=>$aggregate['total'],'difference'=>$count-$aggregate['total'],
            'match'=>$count===$aggregate['total'],'note'=>'Current snapshot comparison; only meaningful after outbox backlog drains.'];
    }

    private static function readFilters(string $path): array
    {
        if (!str_starts_with($path,'/') || is_link($path) || !is_file($path) || filesize($path)>8192) {
            throw new \InvalidArgumentException('Expected absolute regular filters JSON file <=8 KiB.');
        }
        $filters=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($filters) || array_is_list($filters)) throw new \InvalidArgumentException('Expected JSON filter object.');
        return $filters;
    }

    private static function limit(string $raw,int $max): int
    {
        $limit=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($limit===false) throw new \InvalidArgumentException('Invalid limit.');
        return $limit;
    }

    private static function usage(): never
    {
        throw new \InvalidArgumentException('Usage: analytics:replication status | work [1..100] | drain [cycles 1..1000] [batch 1..100] | backfill PROJECT_UUID [1..2000] | replay PROJECT_UUID dead|completed [1..1000] | aggregate FILTERS_JSON | compare FILTERS_JSON');
    }
}
