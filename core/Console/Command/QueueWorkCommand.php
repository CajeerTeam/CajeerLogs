<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Queue\{PostgresQueueDriver,Worker};
use Cajeer\Logs\Database\ConnectionManager;
final class QueueWorkCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int {
        $queue=$command==='queue:work' ? null : $this->app->container()->get(PostgresQueueDriver::class);
        $id=$args[0]??'';
        $validId=count($args)===1 && preg_match('/^[1-9][0-9]{0,18}$/D',$id) && (strlen($id)<19 || strcmp($id,'9223372036854775807')<=0);
        $result=match($command) {
            'queue:work'=>$args===[]?(new Worker($this->app->container()->get(ConnectionManager::class),$this->app->config()->get('queue.max_jobs'),$this->app->basePath('migrations/pgsql'),$this->app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->schema()))->run(): (count($args)===1 && ctype_digit($id) && (int)$id>=1 && (int)$id<=10000?(new Worker($this->app->container()->get(ConnectionManager::class),$this->app->config()->get('queue.max_jobs'),$this->app->basePath('migrations/pgsql'),$this->app->container()->get(\Cajeer\Logs\Database\ConnectionFactory::class)->schema()))->run((int)$id):null),
            'queue:status'=>$args===[]?$queue->counts():null,
            'queue:list'=>count($args)===1 && in_array($id,['pending','running','completed','dead','cancelled'],true)?$queue->list($id):null,
            'queue:history'=>$validId?$queue->history($id):null,
            'queue:retry'=>$validId?['changed'=>$queue->retry($id)]:null,
            'queue:discard'=>$validId?['changed'=>$queue->discard($id)]:null,
            default=>null,
        };
        if ($result===null) { fwrite(STDERR,"Invalid queue arguments; see docs/Queue-Lifecycle.md.\n");return 64; }
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        return ($result['changed']??true)===false?1:0;
    }
}
