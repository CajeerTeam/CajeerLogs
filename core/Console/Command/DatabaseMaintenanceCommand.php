<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\{DatabaseException,DatabaseStatus,IntegrityInspector,MigrationRunner,PerformanceInspector};
use Cajeer\Logs\Kernel\Application;
use PDO;

final class DatabaseMaintenanceCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $limit=20; $project=null; $trace=null; $analyze=false; $filters=null;
        if (in_array($command,['db:integrity','db:verify'],true)) {
            if (count($args)>1 || (isset($args[0])&&!preg_match('/^(?:[1-9][0-9]?|100)$/D',$args[0]))) throw new DatabaseException('Usage: '.$command.' [sample limit 1..100]');
            $limit=(int)($args[0]??20);
        } elseif ($command==='db:performance') {
            if ($args!==[]) throw new DatabaseException('Usage: db:performance');
        } elseif ($command==='db:explain') {
            $project=array_shift($args); if ($project===null) throw new DatabaseException('Usage: db:explain PROJECT [--trace TRACE] [--filters JSON] [--analyze]');
            while ($args!==[]) {
                $flag=array_shift($args);
                if ($flag==='--analyze' && !$analyze) $analyze=true;
                elseif ($flag==='--trace' && $trace===null && $args!==[]) $trace=array_shift($args);
                elseif ($flag==='--filters' && $filters===null && $args!==[]) {
                    $raw=array_shift($args);
                    if (strlen($raw)>32768) throw new DatabaseException('Search filters exceed limit.');
                    try { $obj=json_decode($raw,false,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new DatabaseException('Expected a JSON object of search parameters.'); }
                    if (!$obj instanceof \stdClass) throw new DatabaseException('Expected a JSON object of search parameters.');
                    $filters=(array)$obj;
                    \Cajeer\Logs\Database\PerformanceInspector::queries($project,$trace,$filters);
                }
                else throw new DatabaseException('Usage: db:explain PROJECT [--trace TRACE] [--filters JSON] [--analyze]');
            }
        } else throw new DatabaseException('Unknown database maintenance command.');
        $pdo=$this->app->container()->get(PDO::class);
        $guard=$this->app->container()->get(MigrationRunner::class)->guard($command==='db:integrity');
        try {
            if ($command==='db:verify') {
                // The shared schema lock spans every check. Data audit uses its own
                // repeatable-read snapshot and never modifies the installed database.
                $database=$this->app->container()->get(DatabaseStatus::class)->check();
                $report=['version'=>$this->app->version(),'status'=>'down','database'=>$database,'integrity'=>null,'active_administrators'=>null];
                if ($database['status']==='ok') {
                    $report['active_administrators']=(int)$pdo->query("SELECT count(*) FROM users WHERE role='administrator' AND blocked_at IS NULL")->fetchColumn();
                    $report['integrity']=(new IntegrityInspector($pdo))->inspect($limit);
                    $report['status']=$report['active_administrators']<1?'down':$report['integrity']['status'];
                }
                echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
                return match($report['status']) {'ok'=>0,'issues'=>2,default=>1};
            }
            $report=match($command) {
                'db:integrity'=>(new IntegrityInspector($pdo))->inspect($limit),
                'db:performance'=>(new PerformanceInspector($pdo))->report(),
                'db:explain'=>(new PerformanceInspector($pdo))->explain($project,$trace,$analyze,$filters),
            };
            echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return ($report['status']??'ok')==='ok'?0:2;
        } finally { $guard->release(); }
    }
}
