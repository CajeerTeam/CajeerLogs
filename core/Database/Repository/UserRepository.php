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
        $query = $this->pdo->prepare('SELECT id, email, password_hash, role, blocked_at, two_factor_enabled, created_at, updated_at, display_name, last_login_at FROM users WHERE lower(email) = :email');
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

    /** Works even on a partially migrated users table (blocked_at arrived in 0003). */
    public function verifyInstallerAdministrator(string $email, string $password): ?string
    {
        $query = $this->pdo->prepare("SELECT id,email,password_hash,role,to_jsonb(u)->>'blocked_at' AS blocked_at FROM users u WHERE lower(email)=:email");
        $query->execute(['email'=>self::email($email)]);
        $existing = $query->fetch();
        if ($existing) {
            if ($existing['role'] !== 'administrator' || $existing['blocked_at'] !== null || !password_verify($password, $existing['password_hash'])) {
                throw new DatabaseException('Existing user does not match the requested administrator; no password was changed.');
            }
            return $existing['id'];
        }
        if ($this->pdo->query("SELECT EXISTS(SELECT 1 FROM users WHERE role='administrator')")->fetchColumn() === true) {
            throw new DatabaseException('An administrator already exists; installation will not create another.');
        }
        if ($this->pdo->query("SELECT to_regclass('settings') IS NOT NULL")->fetchColumn() === true && $this->pdo->query("SELECT EXISTS(SELECT 1 FROM settings WHERE key='installation.completed')")->fetchColumn() === true) {
            throw new DatabaseException('Installation was previously completed; use administrative recovery.');
        }
        return null;
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
            $id = $this->verifyInstallerAdministrator($email, $password);
            $created = $id === null;
            if ($created) { $id = $this->create($email, $password, 'administrator'); }
            $record = $this->pdo->prepare("INSERT INTO settings(key,value) VALUES ('installation.completed',jsonb_build_object('administrator_id',CAST(:id AS text),'completed_at',now(),'origin',CAST(:origin AS text))) ON CONFLICT(key) DO NOTHING");
            $record->execute(['id'=>$id,'origin'=>$created?'created':'existing']);
            if ($record->rowCount() === 1) {
                (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record($created?'installation.administrator_created':'installation.administrator_verified',[],null,$id);
            }
            return $created;
        }, maxAttempts: 3);
    }

    /** Trusted CLI recovery; never changes roles, tokens or enrolled TOTP. */
    public function resetAdministratorPassword(string $email, string $password, bool $unblock = false): array
    {
        \Cajeer\Logs\Installer\AdministratorPassword::validate($password);
        $email = self::email($email);
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        return (new TransactionManager($this->pdo))->run(function () use ($email,$hash,$unblock) {
            $this->pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:identity:' || current_schema(),0))")->closeCursor();
            $user = $this->findByEmail($email);
            if ($user === null || $user['role'] !== 'administrator') { throw new DatabaseException('Administrator not found.'); }
            if ($user['blocked_at'] !== null && !$unblock) { throw new DatabaseException('Administrator is blocked; explicit --unblock required.'); }
            $q = $this->pdo->prepare('UPDATE users SET password_hash=:hash,updated_at=now()' . ($unblock?',blocked_at=NULL':'') . ' WHERE id=:id');
            $q->execute(['hash'=>$hash,'id'=>$user['id']]);
            $q = $this->pdo->prepare('DELETE FROM auth_sessions WHERE user_id=:id');
            $q->execute(['id'=>$user['id']]); $revoked = $q->rowCount();
            $q = $this->pdo->prepare('DELETE FROM user_totp WHERE user_id=:id AND pending_session IS NOT NULL');
            $q->execute(['id'=>$user['id']]);
            (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('auth.administrator_password_reset_cli',['sessions_revoked'=>$revoked,'unblocked'=>$unblock && $user['blocked_at'] !== null],null,$user['id']);
            return ['user_id'=>$user['id'],'sessions_revoked'=>$revoked,'two_factor_enabled'=>(bool)$user['two_factor_enabled'],'unblocked'=>$unblock && $user['blocked_at'] !== null];
        }, maxAttempts: 3);
    }
}
