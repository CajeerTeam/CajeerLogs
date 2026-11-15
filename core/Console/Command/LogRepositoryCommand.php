<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\{DatabaseException,MigrationRunner,TransactionManager};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{LogQuery,LogRepository};
use PDO;

/** Host-operator commands; not accessible from HTTP. */
final class LogRepositoryCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $query=null;
        if ($command==='logs:export-jsonl') {
            if (count($args)<1 || count($args)>2) throw new DatabaseException('Usage: logs:export-jsonl PROJECT [SOURCE_UUID]');
            $query=LogQuery::parse(['project_id'=>$args[0],'limit'=>'50']+(isset($args[1])?['source_id'=>$args[1]]:[]));
        } elseif ($command==='logs:legacy-count') {
            if ($args!==[]) throw new DatabaseException('Usage: logs:legacy-count');
        } elseif ($command==='logs:legacy-list') {
            if (count($args)>2 || (isset($args[0]) && !preg_match('/^(?:[1-9][0-9]?|100)$/D',$args[0]))) throw new DatabaseException('Usage: logs:legacy-list [LIMIT [AFTER_UUID]]');
            if (isset($args[1])) LogQuery::uuid($args[1]);
        } elseif ($command==='logs:legacy-adopt') {
            if (count($args)!==4 || $args[3]!=='--confirm') throw new DatabaseException('Usage: logs:legacy-adopt EVENT PROJECT SOURCE_UUID --confirm');
        } else throw new DatabaseException('Unknown log repository command.');
        $pdo=$this->app->container()->get(PDO::class);
        $guard=$this->app->container()->get(MigrationRunner::class)->guard();
        $repo=new LogRepository($pdo); $tx=new TransactionManager($pdo);
        try {
            if ($command==='logs:export-jsonl') {
                // No retries: stdout is an external side effect. A consistent snapshot spans pages.
                $tx->run(function () use ($repo,$query) {
                    foreach ($repo->iterateBounded($query) as $row) {
                        $line=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
                        for ($offset=0;$offset<strlen($line);$offset+=$n) {
                            $n=fwrite(STDOUT,substr($line,$offset));
                            if ($n===false || $n===0) throw new DatabaseException('Export output failed; discard incomplete file.');
                        }
                    }
                },isolation:'REPEATABLE READ',readOnly:true);
            } elseif ($command==='logs:legacy-count') {
                echo json_encode($repo->legacyCounts(),JSON_THROW_ON_ERROR).PHP_EOL;
            } elseif ($command==='logs:legacy-list') {
                echo json_encode($repo->legacyList((int)($args[0]??50),$args[1]??null),JSON_THROW_ON_ERROR).PHP_EOL;
            } else {
                $tx->run(fn()=>$repo->adoptLegacy($args[0],$args[1],$args[2]),maxAttempts:3);
                echo json_encode(['adopted'=>$args[0]],JSON_THROW_ON_ERROR).PHP_EOL;
            }
            return 0;
        } finally { $guard->release(); }
    }
}
