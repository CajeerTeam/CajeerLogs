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
        $pdo=$this->app->container()->get(PDO::class);$retention=new Retention($pdo);
        $id=$args[0]??'';$days=$args[1]??'';
        $result=match($command) {
            'scheduler:run'=>$args===[]?(new Scheduler($pdo))->run():null,
            'retention:list'=>$args===[]?$retention->policies():null,
            'retention:set'=>count($args)===2 && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id) && ($days==='off'||ctype_digit($days)&&(int)$days>=1&&(int)$days<=3650)?['changed'=>$retention->set($id,$days==='off'?null:(int)$days)]:null,
            'retention:run'=>$args===[]?['removed'=>$retention->run()]: (count($args)===1 && ctype_digit($id) && (int)$id>=1 && (int)$id<=10000?['removed'=>$retention->run((int)$id)]:null),
            default=>null,
        };
        if ($result===null) { fwrite(STDERR,"Invalid lifecycle arguments; see docs/Queue-Lifecycle.md.\n");return 64; }
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
        if ($command==='scheduler:run') { foreach ($result as $task) { if (isset($task['error'])) return 1; } }
        return ($result['changed']??true)===false?1:0;
    }
}
