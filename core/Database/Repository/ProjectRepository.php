<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Database\DatabaseException;
use PDO;

final class ProjectRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validate(string $name, string $slug): void
    {
        if (trim($name) === '' || strlen($name) > 255 || strlen($slug) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) { throw new DatabaseException('Invalid project/source name or slug.'); }
    }

    public function create(string $name, string $slug): string
    {
        self::validate($name, $slug);
        $stmt = $this->pdo->prepare('INSERT INTO projects(name, slug) VALUES (:name, :slug) RETURNING id');
        $stmt->execute(['name' => $name, 'slug' => $slug]);
        return (string) $stmt->fetchColumn();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
