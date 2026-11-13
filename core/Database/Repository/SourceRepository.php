<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use PDO;

final class SourceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $projectId, string $name, string $slug): string
    {
        ProjectRepository::validate($name, $slug);
        $stmt = $this->pdo->prepare('INSERT INTO sources(project_id, name, slug) VALUES (:project, :name, :slug) RETURNING id');
        $stmt->execute(['project' => $projectId, 'name' => $name, 'slug' => $slug]);
        return (string) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sources WHERE project_id = :project AND id = :id');
        $stmt->execute(['project' => $projectId, 'id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
