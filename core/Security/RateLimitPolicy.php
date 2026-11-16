<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Http\Request;

/** Shared budgets across routes; keys contain only hashes of canonical peer or authenticated IDs. */
final class RateLimitPolicy
{
    public const DEFAULTS=['search'=>120,'analytics'=>30,'export'=>10,'admin_write'=>60];
    public function __construct(private readonly int $global,private readonly array $rates,private readonly int $burstPercent=100) {
        if ($global<1 || $global>100000 || $burstPercent<1 || $burstPercent>100 || array_keys($rates)!==array_keys(self::DEFAULTS)) throw new \InvalidArgumentException('Invalid API rate policy.');
        foreach ($rates as $rate) if (!is_int($rate)||$rate<1||$rate>100000) throw new \InvalidArgumentException('Invalid API operation limit.');
    }
    public static function fromConfig(ConfigRepository $c): self {
        $rates=[];
        foreach (self::DEFAULTS as $key=>$default) $rates[$key]=self::integer($c->get('api.rate_'.$key,$default),100000);
        return new self(self::integer($c->get('api.rate_limit'),100000),$rates,self::integer($c->get('api.rate_burst_percent',100),100));
    }
    private static function integer(mixed $v,int $max): int {
        $n=is_bool($v)?false:filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($n===false) throw new \InvalidArgumentException('Invalid API rate policy configuration.');
        return $n;
    }
    private function burst(int $rate): int { return max(1,intdiv($rate*$this->burstPercent,100)); }
    public function snapshot(): array {
        $out=[]; foreach (['global'=>$this->global]+$this->rates as $key=>$rate) $out[$key]=['per_minute'=>$rate,'burst'=>$this->burst($rate)]; return $out;
    }
    public function peer(RateLimiter $limiter,Request $r): void { $limiter->enforceBucket('global-peer:'.IngestAuthorization::peerKey($r),$this->global,$this->burst($this->global)); }
    public function principal(RateLimiter $limiter,string $kind,string $id,?string $operation=null): void {
        $key=$kind.':'.$id;
        $limiter->enforceBucket('global-principal:'.$key,$this->global,$this->burst($this->global));
        if ($operation!==null) { $rate=$this->rates[$operation]??throw new \InvalidArgumentException('Unknown rate policy.'); $limiter->enforceBucket($operation.':'.$key,$rate,$this->burst($rate)); }
    }
}
