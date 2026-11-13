<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use PDO;

final class AuthCleanupCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(): int
    {
        $pdo=$this->app->container()->get(PDO::class);
        $sessions=$pdo->exec('DELETE FROM auth_sessions WHERE token_hash IN (SELECT token_hash FROM auth_sessions WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)');
        $buckets=$pdo->exec('DELETE FROM auth_rate_limits WHERE (bucket_key,window_start) IN (SELECT bucket_key,window_start FROM auth_rate_limits WHERE window_start<extract(epoch from now())-7200 ORDER BY window_start LIMIT 10000 FOR UPDATE SKIP LOCKED)');
        echo "Removed $sessions expired sessions and $buckets old rate-limit buckets.\n";
        return 0;
    }
}
