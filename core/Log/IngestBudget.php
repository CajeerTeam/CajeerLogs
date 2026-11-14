<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use PDO;

/** Cooperative monotonic budget, with a remaining-time cap on transactional SQL. */
final class IngestBudget
{
    private readonly int $end;
    public function __construct(int $milliseconds) { $this->end=hrtime(true)+$milliseconds*1000000; }
    public function remaining(): int { return max(0,(int)floor(($this->end-hrtime(true))/1000000)); }
    public function check(): void { if ($this->remaining()===0) throw self::unavailable(); }
    public function limitSql(PDO $pdo,int $configured): void
    {
        $this->check();
        if (!$pdo->inTransaction()) throw new \LogicException('Ingest SQL budget requires a transaction.');
        $pdo->exec('SET LOCAL statement_timeout = '.max(1,min($configured,$this->remaining())));
    }
    public static function unavailable(): HttpException { return new HttpException(503,'database_unavailable','Log storage temporarily unavailable.',['Retry-After'=>'5']); }
}
