<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use PDO;
use Cajeer\Logs\Http\HttpException;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}

    /** Database clock, atomic increments shared by all PHP workers. */
    private function fixed(string $key, int $limit, int $seconds): array
    {
        if ($limit < 1 || $seconds < 1 || $seconds > 3600) { throw new \InvalidArgumentException('Invalid rate limit.'); }
        $stmt = $this->pdo->prepare('INSERT INTO auth_rate_limits(bucket_key,window_start,hits) VALUES (:key, floor(extract(epoch from clock_timestamp()) / CAST(:seconds AS integer))::bigint * CAST(:width AS bigint), 1) ON CONFLICT(bucket_key,window_start) DO UPDATE SET hits = LEAST(auth_rate_limits.hits::bigint + 1, 2147483647)::integer RETURNING hits, GREATEST(1,ceil(window_start + CAST(:retry_width AS bigint) - extract(epoch from clock_timestamp())))::integer AS retry_after');
        $stmt->execute(['key'=>hash('sha256', $key . ':' . $seconds), 'seconds'=>$seconds,'width'=>$seconds,'retry_width'=>$seconds]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Rate limit storage unavailable.');
        return ['allowed'=>(int)$row['hits'] <= $limit,'retry_after'=>(int)$row['retry_after']];
    }

    public function hit(string $key, int $limit = 120, int $seconds = 60): bool
    { return $this->fixed($key,$limit,$seconds)['allowed']; }

    public function enforceBucket(string $key,int $rate,int $burst): void
    {
        if ($rate<1 || $rate>100000 || $burst<1 || $burst>$rate) throw new \InvalidArgumentException('Invalid rate policy.');
        $stmt=$this->pdo->prepare('SELECT allowed,retry_after,remaining FROM api_rate_charge(:key,:rate,:burst)');
        $stmt->execute(['key'=>hash('sha256','v1:'.$key.':'.$rate.':'.$burst),'rate'=>$rate,'burst'=>$burst]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Rate limit storage unavailable.');
        if (!in_array($row['allowed'],[true,1,'1','t'],true)) $this->reject((int)$row['retry_after']);
    }
    private function reject(int $retry): never
    { throw new HttpException(429,'rate_limited','Too many requests. Try again later.', ['Retry-After'=>(string)max(1,$retry),'Cache-Control'=>'no-store']); }

    public function enforce(string $key, int $limit, int $seconds): void
    {
        $decision=$this->fixed($key,$limit,$seconds);
        if (!$decision['allowed']) $this->reject($decision['retry_after']);
    }
}
