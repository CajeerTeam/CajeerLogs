<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;
use PDOException;

/** Read-only HTTP search budget. Authentication/rate-limit writes happen before this transaction. */
final class SearchRuntime
{
    public function __construct(private readonly PDO $pdo,private readonly int $milliseconds)
    {
        if ($milliseconds<1 || $milliseconds>60000) throw new \InvalidArgumentException('Invalid search budget.');
    }

    public function run(LogQuery $query,callable $read): mixed
    {
        $end=hrtime(true)+$this->milliseconds*1000000;
        $limit=function() use ($end): void {
            $remaining=(int)floor(($end-hrtime(true))/1000000);
            if ($remaining<1) throw self::timeout();
            $this->pdo->exec('SET LOCAL statement_timeout = '.$remaining);
        };
        try {
            return (new TransactionManager($this->pdo))->run(function () use ($query,$read,$limit) {
                $limit();
                if (isset($query->filters['q'])) {
                    // querytree identifies expressions with no positive indexable
                    // term (e.g. -error or error OR -warning). Bound those scans.
                    $stmt=$this->pdo->prepare('SELECT numnode(q) AS nodes,querytree(q) AS tree FROM (SELECT '.$query->textFunction()."('simple', :q) AS q) AS parsed");
                    $stmt->execute(['q'=>$query->filters['q']]);
                    $row=$stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
                    if (!$row) throw new \RuntimeException('Cannot inspect search expression.');
                    self::validateText((int)$row['nodes'],(string)$row['tree'],$query);
                    $limit();
                }
                return $read(new LogRepository($this->pdo),$limit);
            },readOnly:true);
        } catch (PDOException $e) {
            if ((string)($e->errorInfo[0]??$e->getCode())==='57014') throw self::timeout();
            throw $e;
        }
    }
    public static function validateText(int $nodes,string $tree,LogQuery $query): void
    {
        if ($nodes>128) throw new HttpException(422,'invalid_query','Full-text query is too complex.');
        // Empty tsquery has no matches; retain historical empty-result behavior.
        if ($nodes>0 && in_array(trim($tree),['','T'],true) && !LogQuery::boundedRange($query->filters)) {
            throw new HttpException(422,'invalid_query','Search without an indexable term requires from and to spanning at most 31 days.');
        }
    }
    private static function timeout(): HttpException
    {
        return new HttpException(503,'search_timeout','Search exceeded its processing budget. Narrow the query.', ['Retry-After'=>'5']);
    }
}
