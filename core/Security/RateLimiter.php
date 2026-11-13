<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use PDO;
use Cajeer\Logs\Http\HttpException;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}

    /** Database clock, atomic increments shared by all PHP workers. */
    public function hit(string $key, int $limit = 120, int $seconds = 60): bool
    {
        if ($limit < 1 || $seconds < 1 || $seconds > 3600) { throw new \InvalidArgumentException('Invalid rate limit.'); }
        $stmt = $this->pdo->prepare('INSERT INTO auth_rate_limits(bucket_key,window_start,hits) VALUES (:key, floor(extract(epoch from clock_timestamp()) / CAST(:seconds AS integer))::bigint * CAST(:width AS bigint), 1) ON CONFLICT(bucket_key,window_start) DO UPDATE SET hits = LEAST(auth_rate_limits.hits::bigint + 1, 2147483647)::integer RETURNING hits');
        $stmt->execute(['key'=>hash('sha256', $key . ':' . $seconds), 'seconds'=>$seconds,'width'=>$seconds]);
        return (int)$stmt->fetchColumn() <= $limit;
    }

    public function enforce(string $key, int $limit, int $seconds): void
    {
        if (!$this->hit($key,$limit,$seconds)) { throw new HttpException(429,'rate_limited','Too many requests. Try again later.', ['Retry-After'=>(string)$seconds]); }
    }
}
