<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Queue\{PostgresQueueDriver,Worker,QueueAccounting,QueueFairness,BackpressurePolicy,WorkerPolicy,WorkerPool,WorkerRegistry,RetryPolicy,QueueOperations};
use Cajeer\Logs\Database\ConnectionManager;
final class QueueWorkCommand
{
    public function __construct(private readonly Application $app) {}
    private function worker(): Worker
    {
        $heartbeat=new \Cajeer\Logs\Observability\WorkerHeartbeat($this->app->basePath('storage/cache'));
        return new Worker($this->app->container()->get(ConnectionManager::class),$this->app->config()->get('queue.max_jobs'),$this->app->basePath('migrations/pgsql'),$this->app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->schema(),fn()=> $heartbeat->record('queue'),WorkerPolicy::fromConfig($this->app->config()),new WorkerRegistry($this->app->basePath('storage/cache')),RetryPolicy::fromConfig($this->app->config()),fn(\PDO $pdo)=>$this->app->container()->get(\Cajeer\Logs\Database\DatabaseStatus::class)->check(true,false,$pdo)['status']==='ok');
    }
    public function handle(string $command,array $args): int {
        $policy=WorkerPolicy::fromConfig($this->app->config());
        if ($command==='queue:pool') {
            $size=count($args)===0?$policy->get('processes'):(count($args)===1?filter_var($args[0],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>32]]):false);
            if ($size===false) {fwrite(STDERR,"Usage: queue:pool [1..32]\n");return 64;}
            return (new WorkerPool($policy,[PHP_BINARY,$this->app->basePath('bin/cajeer'),'queue:work']))->run($size);
        }
        if ($command==='queue:workers' || $command==='queue:pool-health') {
            $expected=$policy->get('processes');
            if ($command==='queue:pool-health' && count($args)===1) $expected=filter_var($args[0],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>32]]);
            elseif ($args!==[]) return 64;
            if ($expected===false) return 64;
            $report=(new WorkerRegistry($this->app->basePath('storage/cache')))->snapshot();
            $report['policy']=$policy->values;
            $report['retry_policy']=RetryPolicy::fromConfig($this->app->config())->values;
            if ($command==='queue:pool-health') {
                $report['local_active']=count(array_filter($report['workers'],fn($w)=>$w['host']===WorkerRegistry::host() && $w['pool']!==null && $w['status']==='ok' && WorkerRegistry::localAlive($w)));
                $report['expected']=$expected;
                $report['status']=$report['available']&&!$report['truncated']&&$report['local_active']>=$report['expected']?'ok':'down';
            }
            echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return $report['available']&&($report['status']??'ok')==='ok'?0:1;
        }
        if (in_array($command,['queue:dispatch','queue:pause','queue:resume','queue:drain'],true)) {
            $ops=new QueueOperations($this->app->container()->get(\PDO::class));
            try {
                $result=match($command) {
                    'queue:dispatch' => $args===[]?$ops->status():null,
                    'queue:pause' => count($args)>=1 && count($args)<=2 && end($args)==='--confirm'
                        ? $ops->setPaused(true,count($args)===2?$args[0]:null):null,
                    'queue:resume' => $args===['--confirm']?$ops->setPaused(false):null,
                    'queue:drain' => count($args)>=1 && count($args)<=2 && end($args)==='--confirm'
                        ? $ops->drain(count($args)===2?QueueOperations::duration($args[0]):0,'operator drain'):null,
                    default => null,
                };
            } catch (\InvalidArgumentException $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);return 64;}
            if ($result===null) {fwrite(STDERR,"Usage: queue:dispatch | queue:pause [REASON] --confirm | queue:resume --confirm | queue:drain [SECONDS] --confirm\n");return 64;}
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return ($result['drained']??true)?0:1;
        }
        if (in_array($command,['queue:projects','queue:project','queue:project-set','queue:project-verify','queue:project-reconcile'],true)) {
            $fair=new QueueFairness($this->app->container()->get(\PDO::class));
            try {
                $result=match ($command) {
                    'queue:projects' => $args===[]?$fair->snapshot():null,
                    'queue:project' => count($args)===1?$fair->snapshot($args[0]):null,
                    'queue:project-verify' => $args===[]?$fair->verify():null,
                    'queue:project-reconcile' => $args===['--confirm']?$fair->reconcile():null,
                    'queue:project-set' => count($args)===7 && $args[6]==='--confirm' ?
                        $fair->configure($args[0],QueueFairness::policy(array_combine(
                            ['weight','max_jobs','max_events','max_bytes','max_running_jobs'],array_slice($args,1,5)))) : null,
                    default => null,
                };
            } catch (\InvalidArgumentException $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);return 64;}
            if ($result===null) {fwrite(STDERR,"Usage: queue:projects | queue:project UUID | queue:project-set UUID WEIGHT MAX_JOBS MAX_EVENTS MAX_BYTES MAX_RUNNING --confirm | queue:project-verify | queue:project-reconcile --confirm\n");return 64;}
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return ($result['valid']??true)===false?1:0;
        }
        $queue=$command==='queue:work' ? null : $this->app->container()->get(PostgresQueueDriver::class);
        $id=$args[0]??'';
        $validId=count($args)===1 && preg_match('/^[1-9][0-9]{0,18}$/D',$id) && (strlen($id)<19 || strcmp($id,'9223372036854775807')<=0);
        $result=match($command) {
            'queue:work'=>$args===[]?$this->worker()->run(): (count($args)===1 && ctype_digit($id) && (int)$id>=1 && (int)$id<=10000?$this->worker()->run((int)$id):null),
            'queue:status'=>$args===[]?$queue->counts():null,
            'queue:recover'=>count($args)>=1 && count($args)<=2 && end($args)==='--confirm' && (count($args)===1 || (ctype_digit($id) && (int)$id>=1 && (int)$id<=1000)) ? $queue->recoverExpired(count($args)===2?(int)$id:100):null,
            'queue:capacity'=>$args===[]?(new QueueAccounting($this->app->container()->get(\PDO::class)))->status()+BackpressurePolicy::fromConfig($this->app->config())->limits():null,
            'queue:verify'=>$args===[]?(new QueueAccounting($this->app->container()->get(\PDO::class)))->verify():null,
            'queue:reconcile'=>$args===['--confirm']?(new QueueAccounting($this->app->container()->get(\PDO::class)))->reconcile():null,
            'queue:list'=>count($args)===1 && in_array($id,['pending','running','completed','dead','cancelled'],true)?$queue->list($id):null,
            'queue:history'=>$validId?$queue->history($id):null,
            'queue:retry'=>$validId?['changed'=>(new \Cajeer\Logs\Queue\DeadLetterManager($this->app->container()->get(\PDO::class),RetryPolicy::fromConfig($this->app->config())))->cliChange($id,'retry')]:null,
            'queue:discard'=>$validId?['changed'=>(new \Cajeer\Logs\Queue\DeadLetterManager($this->app->container()->get(\PDO::class),RetryPolicy::fromConfig($this->app->config())))->cliChange($id,'discard')]:null,
            default=>null,
        };
        if ($result===null) { fwrite(STDERR,"Invalid queue arguments; see docs/Queue-Lifecycle.md.\n");return 64; }
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        return ($result['changed']??true)===false || ($result['valid']??true)===false?1:0;
    }
}
