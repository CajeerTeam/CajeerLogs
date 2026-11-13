<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Database\DatabaseException;
use PDO;

final class TokenRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Plaintext is returned exactly once; only its SHA-256 digest is stored. */
    public function create(string $name, array $scopes, ?string $projectId = null, ?string $sourceId = null, ?\DateTimeImmutable $expires = null): array
    {
        if (trim($name) === '' || strlen($name) > 255 || $scopes === [] || !array_is_list($scopes) || array_filter($scopes, static fn(mixed $scope): bool => !is_string($scope) || !in_array($scope, ['logs:write','logs:read','system:read'], true)) !== [] || ($sourceId !== null && $projectId === null) || ($expires !== null && $expires <= new \DateTimeImmutable())) { throw new DatabaseException('Invalid token metadata.'); }
        $token = 'cl_' . bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('INSERT INTO api_tokens(name, token_hash, scopes, project_id, source_id, expires_at) VALUES (:name, :hash, CAST(:scopes AS jsonb), :project, :source, :expires) RETURNING id');
        $stmt->execute(['name' => $name, 'hash' => hash('sha256', $token), 'scopes' => json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR), 'project' => $projectId, 'source' => $sourceId, 'expires' => $expires?->format(DATE_ATOM)]);
        return ['id' => (string) $stmt->fetchColumn(), 'token' => $token];
    }

    public function findActive(string $token): ?array
    {
        if (!preg_match('/^cl_[a-f0-9]{64}$/D', $token)) { return null; }
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = now() WHERE token_hash = :hash AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()) RETURNING id, name, scopes, project_id, source_id, expires_at');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        $row['scopes'] = json_decode($row['scopes'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function revoke(string $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }
}
