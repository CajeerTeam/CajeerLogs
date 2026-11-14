<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Integration\WebhookOutbox;
use PDO;
final class WebhookCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $outbox=new WebhookOutbox($this->app->container()->get(PDO::class));
        $uuid=static function(string $id): string { if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id)) { throw new \InvalidArgumentException('Invalid UUID.'); } return $id; };
        $result=match($command) {
            'webhook:add'=>count($args)===3?['id'=>$outbox->add($uuid($args[0]),$args[1],$args[2])]:null,
            'webhook:list'=>$args===[]?$outbox->list():null,
            'webhook:cleanup'=>$args===[]?['removed'=>$outbox->cleanup()]:null,
            'webhook:history'=>count($args)===1?$outbox->history($uuid($args[0])):null,
            'webhook:disable'=>count($args)===1?['changed'=>$outbox->disable($uuid($args[0]))]:null,
            'webhook:retry'=>count($args)===1?['changed'=>$outbox->retry($uuid($args[0]))]:null,
            'webhook:work'=>($args===[]||count($args)===1&&ctype_digit($args[0]))?$outbox->work(isset($args[0])?(int)$args[0]:20):null,
            default=>null,
        };
        if ($result===null) { fwrite(STDERR,"Invalid webhook arguments; see docs/SDK-Integrations.md.\n");return 64; }
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return ($result['changed']??true)===false?1:0;
    }
}
