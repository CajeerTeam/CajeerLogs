<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Scheduler\Scheduler;
use Cajeer\Logs\Lifecycle\Retention;
use PDO;
final class SchedulerRunCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int {
        $pdo=$this->app->container()->get(PDO::class);$cfg=$this->app->config();$batch=$cfg->get('operations.retention_batch_size');$timeout=$cfg->get('operations.retention_timeout_ms');$retention=new Retention($pdo,$timeout);
        $id=$args[0]??'';$days=$args[1]??'';
        $result=match($command) {
            'scheduler:run'=>$args===[]?(new Scheduler($pdo,$cfg->get('security.session_idle_seconds'),$batch,$timeout,$cfg->get('operations.queue_retention_floor_seconds'),$cfg->get('operations.webhook_retention_days'),$cfg->get('operations.retention_scheduler_enabled'),$cfg->get('operations.retention_window_utc')))->run():null,
            'retention:list'=>$args===[]?$retention->policies():null,
            'retention:set'=>count($args)===2 && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id) && ($days==='off'||ctype_digit($days)&&(int)$days>=1&&(int)$days<=3650)?['changed'=>$retention->set($id,$days==='off'?null:(int)$days)]:null,
            'retention:preview'=>$args===[]?$retention->preview($batch):(count($args)<=2 && ctype_digit($id) && (int)$id>=1 && (int)$id<=10000 && (count($args)===1 || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$days))?$retention->preview((int)$id,$args[1]??null):null),
            'retention:history'=>$args===[]?$retention->history():null,
            'retention:cycle'=>$args===['--confirm']?$retention->cycle($batch,$cfg->get('operations.retention_cycle_batches'),$cfg->get('operations.retention_pause_ms'),$cfg->get('operations.retention_cycle_budget_ms')):null,
            'retention:run'=>$args===[]?['removed'=>$retention->run($batch)]: (count($args)===1 && ctype_digit($id) && (int)$id>=1 && (int)$id<=10000?['removed'=>$retention->run((int)$id)]:null),
            default=>null,
        };
        if ($result===null) { fwrite(STDERR,"Invalid lifecycle arguments; see docs/Queue-Lifecycle.md.\n");return 64; }
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        if ($command==='scheduler:run') { foreach ($result as $task) { if (isset($task['error'])) return 1; } (new \Cajeer\Logs\Observability\WorkerHeartbeat($this->app->basePath('storage/cache')))->record('scheduler'); }
        return ($result['changed']??true)===false?1:0;
    }
}
