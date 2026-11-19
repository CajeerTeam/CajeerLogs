<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\{MigrationRunner,ConnectionFactory,DatabaseException};
use Cajeer\Logs\Kernel\Application;

final class MigrateCommand
{
    public function __construct(private readonly Application $app) {}
    public static function limits(): array {
        $read=static function(string $key,int $default,int $min,int $max): int {
            $raw=getenv($key); $v=$raw===false?$default:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($v===false) throw new DatabaseException('Invalid '.$key.'.'); return $v;
        };
        return [$read('DB_MIGRATION_STATEMENT_TIMEOUT_MS',600000,1000,86400000),$read('DB_MIGRATION_MAX_BACKFILL_BATCHES',1000,1,100000)];
    }
    public function handle(string $command='migrate'): int
    {
        $factory=$this->app->container()->get(ConnectionFactory::class);
        [$timeout,$batches]=self::limits();
        $runner=in_array($command,['migrate','migrate:certify'],true)?new MigrationRunner($factory->make('migration'),$this->app->basePath('migrations/pgsql'),$factory->schema(),$timeout,$batches):new MigrationRunner($this->app->container()->get(\PDO::class),$this->app->basePath('migrations/pgsql'),$factory->schema(),$timeout,$batches);
        if ($command==='migrate:certify') {
            echo json_encode($runner->certify(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
            return 0;
        }
        if ($command==='migrate:plan' || $command==='migrate:verify') {
            $status=$command==='migrate:plan'?$runner->plan():$runner->status();
            echo json_encode($status,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
            return $command==='migrate:plan'?($status['integrity']==='drift'?1:0):($status['pending']===[]&&$status['integrity']==='verified'?0:1);
        }
        if ($command==='migrate:status') {
            $status=$runner->status();
            foreach ($status['applied'] as $name) echo "[APPLIED] $name\n";
            foreach ($status['pending'] as $name) echo "[PENDING] $name\n";
            echo 'Schema integrity: '.$status['integrity'].PHP_EOL;
            return $status['pending']===[]&&$status['integrity']==='verified'?0:1;
        }
        $applied=$runner->migrate();
        foreach ($applied as $name) echo "Applied $name\n";
        if ($applied===[]) echo "No pending migrations.\n";
        echo "Schema integrity: verified\n";
        return 0;
    }
}
