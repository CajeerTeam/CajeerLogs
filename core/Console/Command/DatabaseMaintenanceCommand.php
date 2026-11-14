<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\{DatabaseException,IntegrityInspector,MigrationRunner,PerformanceInspector};
use Cajeer\Logs\Kernel\Application;
use PDO;

final class DatabaseMaintenanceCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $limit=20; $project=null; $trace=null; $analyze=false;
        if ($command==='db:integrity') {
            if (count($args)>1 || (isset($args[0])&&!preg_match('/^(?:[1-9][0-9]?|100)$/D',$args[0]))) throw new DatabaseException('Usage: db:integrity [sample limit 1..100]');
            $limit=(int)($args[0]??20);
        } elseif ($command==='db:performance') {
            if ($args!==[]) throw new DatabaseException('Usage: db:performance');
        } elseif ($command==='db:explain') {
            $project=array_shift($args); if ($project===null) throw new DatabaseException('Usage: db:explain PROJECT [--trace TRACE] [--analyze]');
            while ($args!==[]) {
                $flag=array_shift($args);
                if ($flag==='--analyze' && !$analyze) $analyze=true;
                elseif ($flag==='--trace' && $trace===null && $args!==[]) $trace=array_shift($args);
                else throw new DatabaseException('Usage: db:explain PROJECT [--trace TRACE] [--analyze]');
            }
        } else throw new DatabaseException('Unknown database maintenance command.');
        $pdo=$this->app->container()->get(PDO::class);
        $guard=$this->app->container()->get(MigrationRunner::class)->guard($command==='db:integrity');
        try {
            $report=match($command) {
                'db:integrity'=>(new IntegrityInspector($pdo))->inspect($limit),
                'db:performance'=>(new PerformanceInspector($pdo))->report(),
                'db:explain'=>(new PerformanceInspector($pdo))->explain($project,$trace,$analyze),
            };
            echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return ($report['status']??'ok')==='ok'?0:2;
        } finally { $guard->release(); }
    }
}
