<?php
declare(strict_types=1);
namespace Cajeer\Logs\Lifecycle;
use PDO;
final class Maintenance
{
    public function __construct(private readonly PDO $pdo) {}
    public function authCleanup(): array {
        return [
            'totp_pending'=>(int)$this->pdo->exec('DELETE FROM user_totp WHERE user_id IN (SELECT user_id FROM user_totp WHERE pending_until<=now() LIMIT 1000 FOR UPDATE SKIP LOCKED)'),
            'sessions'=>(int)$this->pdo->exec('DELETE FROM auth_sessions WHERE token_hash IN (SELECT token_hash FROM auth_sessions WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)'),
            'buckets'=>(int)$this->pdo->exec('DELETE FROM auth_rate_limits WHERE (bucket_key,window_start) IN (SELECT bucket_key,window_start FROM auth_rate_limits WHERE window_start<extract(epoch from now())-7200 ORDER BY window_start LIMIT 10000 FOR UPDATE SKIP LOCKED)'),
            'receipts'=>(int)$this->pdo->exec('DELETE FROM ingest_receipts WHERE (token_id,key_hash) IN (SELECT token_id,key_hash FROM ingest_receipts WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)'),
            'nonces'=>(int)$this->pdo->exec('DELETE FROM ingest_nonces WHERE (token_id,nonce_hash) IN (SELECT token_id,nonce_hash FROM ingest_nonces WHERE expires_at<=now() ORDER BY expires_at LIMIT 10000 FOR UPDATE SKIP LOCKED)'),
        ];
    }
}
