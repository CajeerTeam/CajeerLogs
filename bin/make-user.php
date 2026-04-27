#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Migrator;

$args = $_SERVER['argv'];
array_shift($args);
$username = $args[0] ?? null;
$password = $args[1] ?? null;
if (!$username || !$password) {
    fwrite(STDERR, "Usage: php bin/make-user.php <username> <password> [role]\n");
    exit(1);
}
$role = $args[2] ?? 'admin';
$migrator = new Migrator(Database::pdo());
$migrator->run();
$hash = password_hash($password, PASSWORD_DEFAULT);
$now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
$sql = Database::driver() === 'pgsql'
    ? "INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :hash, :role, 1, {$now}, {$now}) ON CONFLICT (username) DO UPDATE SET password_hash = EXCLUDED.password_hash, role = EXCLUDED.role, is_active = 1, updated_at = NOW()"
    : "INSERT OR REPLACE INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :hash, :role, 1, {$now}, {$now})";
Database::pdo()->prepare($sql)->execute(['username' => $username, 'hash' => $hash, 'role' => $role]);
echo "User saved: {$username}\n";
