<?php
declare(strict_types=1);
namespace Cajeer\Logs\Search;

use Cajeer\Logs\Log\{LogQuery,LogRepository};
use PDO;

/** Compatibility adapter; all PostgreSQL log reads live in LogRepository. */
final class PostgresFullTextSearchEngine
{
    private readonly LogRepository $repository;
    public function __construct(PDO $pdo) { $this->repository=new LogRepository($pdo); }
    public static function predicate(LogQuery $query): array { return LogRepository::predicate($query); }
    public function page(LogQuery $query): array { return $this->repository->page($query); }
    public function find(LogQuery $query,string $id): ?array { return $this->repository->find($query,$id); }
    public function statistics(LogQuery $query): array { return $this->repository->statistics($query); }
}
