<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\ExportQueue;
use PDO;

final class ExportCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $pdo=$this->app->container()->get(PDO::class);
        $guard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
        try {
            $queue=new ExportQueue($pdo,$this->app->basePath('storage/exports/private'));
            if ($command==='exports:work' && (count($args)===0 || count($args)===1 && ctype_digit($args[0]) && (int)$args[0]>=1 && (int)$args[0]<=100)) {
                $result=$queue->work((int)($args[0]??5));
            } elseif ($command==='exports:cleanup' && $args===[]) $result=$queue->cleanup();
            else { fwrite(STDERR,"Usage: exports:work [1..100] | exports:cleanup\n");return 64; }
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
            return $result['failed']??0 ? 1 : 0;
        } finally { $guard->release(); }
    }
}
