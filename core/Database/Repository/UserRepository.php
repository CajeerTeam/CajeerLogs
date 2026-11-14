<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Database\TransactionManager;

use Cajeer\Logs\Database\DatabaseException;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new DatabaseException('Invalid administrator email.'); }
        return $email;
    }

    public function findByEmail(string $email): ?array
    {
        $query = $this->pdo->prepare('SELECT id, email, password_hash, role, blocked_at, two_factor_enabled, created_at, updated_at FROM users WHERE lower(email) = :email');
        $query->execute(['email' => self::email($email)]);
        return $query->fetch() ?: null;
    }

    public function create(string $email, string $password, string $role = 'viewer'): string
    {
        if (!in_array($role, ['administrator', 'operator', 'viewer'], true)) { throw new DatabaseException('Invalid user role.'); }
        if (strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\0")) { throw new DatabaseException('Password must contain 12–1024 bytes and no NUL.'); }
        if (!defined('PASSWORD_ARGON2ID')) { throw new DatabaseException('PHP Argon2id password hashing is required.'); }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $query = $this->pdo->prepare('INSERT INTO users(email, password_hash, role) VALUES (:email, :hash, :role) RETURNING id');
        $query->execute(['email' => self::email($email), 'hash' => $hash, 'role' => $role]);
        return (string) $query->fetchColumn();
    }

    /** Concurrent installers cannot create or overwrite multiple initial administrators. */
    public function ensureFirstAdministrator(string $email, string $password, string $schema): bool
    {
        $email = self::email($email);
        return (new TransactionManager($this->pdo))->run(function () use ($email,$password,$schema) {
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))');
            $lock->execute(['key' => 'cajeerlogs:installer:' . $schema]);
            $lock->closeCursor();
            $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:identity:' || current_schema(),0))")->closeCursor();
            $existing = $this->findByEmail($email);
            if ($existing !== null) {
                if ($existing['role'] !== 'administrator' || $existing['blocked_at'] !== null || !password_verify($password, $existing['password_hash'])) { throw new DatabaseException('Existing user does not match the requested administrator; no password was changed.'); }
                return false;
            }
            if ($this->pdo->query("SELECT EXISTS (SELECT 1 FROM users WHERE role = 'administrator')")->fetchColumn()) { throw new DatabaseException('An administrator already exists; installation will not create another.'); }
            $this->create($email, $password, 'administrator');
            return true;
        }, maxAttempts: 3);
    }
}
