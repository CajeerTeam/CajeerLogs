<?php
declare(strict_types=1);
namespace Cajeer\Logs\Queue;

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Database\DatabaseFailure;
use Cajeer\Logs\Http\HttpException;

/** Retry limits are persisted per job at acceptance; delay settings are operational. */
final class RetryPolicy
{
    public const DEFAULTS=['max_attempts'=>8,'base_seconds'=>5,'max_seconds'=>3600,'jitter_percent'=>25,'outage_base_ms'=>250,'outage_max_ms'=>30000,'outage_max_failures'=>10];
    private const RANGES=['max_attempts'=>[1,100],'base_seconds'=>[1,3600],'max_seconds'=>[1,86400],'jitter_percent'=>[0,100],'outage_base_ms'=>[50,10000],'outage_max_ms'=>[50,60000],'outage_max_failures'=>[1,100]];
    public readonly array $values;
    public function __construct(array $values=[])
    {
        $valid=[];
        foreach (self::RANGES as $key=>[$min,$max]) {
            $raw=$values[$key]??self::DEFAULTS[$key];
            $value=is_bool($raw)?false:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($value===false) throw new \InvalidArgumentException('Invalid queue retry configuration: '.$key);
            $valid[$key]=$value;
        }
        if ($valid['base_seconds']>$valid['max_seconds'] || $valid['outage_base_ms']>$valid['outage_max_ms']) throw new \InvalidArgumentException('Inconsistent queue retry delay limits.');
        $this->values=$valid;
    }
    public static function fromConfig(ConfigRepository $config): self {return new self($config->get('queue.retry',[]));}
    public function get(string $key): int {return $this->values[$key];}
    private static function exponential(int $base,int $cap,int $attempt): int
    {
        // Saturate before multiplication; never overflow for a corrupt attempt number.
        for ($i=1;$i<$attempt && $base<$cap;++$i) $base=min($cap,$base*2);
        return $base;
    }
    public function delayBounds(int $attempt): array
    {
        $base=self::exponential($this->get('base_seconds'),$this->get('max_seconds'),max(1,$attempt));
        $spread=intdiv($base*$this->get('jitter_percent'),100);
        return [max(1,$base-$spread),min($this->get('max_seconds'),$base+$spread)];
    }
    public function delay(int $attempt): int {[$lo,$hi]=$this->delayBounds($attempt);return random_int($lo,$hi);}
    public function outageDelay(int $failure): int
    {
        $cap=self::exponential($this->get('outage_base_ms'),$this->get('outage_max_ms'),max(1,$failure));
        return random_int(max(1,(int)ceil($cap/2)),$cap);
    }
    /** Only safe fixed categories cross the database/API boundary. Infrastructure
     * failures leave the claim fenced for recovery, never blindly replay COMMIT. */
    public static function classify(\Throwable $error): array
    {
        if ($error instanceof \JsonException || ($error instanceof HttpException && in_array($error->status,[403,413,422],true))) return ['action'=>'dead','reason'=>'invalid_payload'];
        $category=DatabaseFailure::category($error);
        if (in_array($category,['connection_lost','connect_timeout','capacity','read_only','authentication','tls','configuration'],true)) return ['action'=>'pause','reason'=>$category];
        if (in_array($category,['statement_timeout','lock_timeout','transaction_conflict'],true)) return ['action'=>'retry','reason'=>$category];
        for ($e=$error;$e!==null;$e=$e->getPrevious()) {
            if (!$e instanceof \PDOException) continue;
            $state=(string)($e->errorInfo[0]??$e->getCode());
            // Permissions, missing/invalid schema objects, unsupported features: do not burn
            // through every job in the queue during a broken deployment.
            if (str_starts_with($state,'42') || str_starts_with($state,'3F') || str_starts_with($state,'0A')) return ['action'=>'pause','reason'=>'database_configuration'];
            return ['action'=>'retry','reason'=>'database_error'];
        }
        return ['action'=>'retry','reason'=>'processing_error'];
    }
}
