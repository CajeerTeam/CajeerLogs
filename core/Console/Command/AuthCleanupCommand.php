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
        $receipts=$pdo->exec('DELETE FROM ingest_receipts WHERE (token_id,key_hash) IN (SELECT token_id,key_hash FROM ingest_receipts WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)');
        $nonces=$pdo->exec('DELETE FROM ingest_nonces WHERE (token_id,nonce_hash) IN (SELECT token_id,nonce_hash FROM ingest_nonces WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)');
        echo "Removed $receipts expired ingest receipts and $nonces expired signature nonces.\n";
        echo "Removed $sessions expired sessions and $buckets old rate-limit buckets.\n";
        return 0;
    }
}
