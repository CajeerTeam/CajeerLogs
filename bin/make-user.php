#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Auth;
use CajeerLogs\Database;
use CajeerLogs\Migrator;

$args = $_SERVER['argv'];
array_shift($args);

$active = 1;
$filtered = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--active=')) {
        $active = ((string)substr($arg, 9)) === '0' ? 0 : 1;
        continue;
    }
    $filtered[] = $arg;
}
$args = $filtered;

$username = trim((string)($args[0] ?? ''));
$password = (string)($args[1] ?? '');
$role = (string)($args[2] ?? 'admin');
$allowedRoles = ['admin', 'operator', 'security', 'viewer'];

if ($username === '') {
    fwrite(STDERR, "Использование: php bin/make-user.php <username> [password] [role] [--active=1]\n");
    fwrite(STDERR, "Если пароль не передан аргументом, команда запросит его интерактивно.\n");
    exit(1);
}
if (!in_array($role, $allowedRoles, true)) {
    fwrite(STDERR, "Недопустимая роль: {$role}. Разрешены: " . implode(', ', $allowedRoles) . "\n");
    exit(1);
}
if ($password === '') {
    fwrite(STDOUT, 'Пароль для ' . $username . ': ');
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'Windows') === false) {
        shell_exec('stty -echo');
        $password = rtrim((string)fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    } else {
        $password = rtrim((string)fgets(STDIN), "\r\n");
    }
}
if (Auth::weakBootstrapPassword($password)) {
    fwrite(STDERR, "Пароль слишком слабый или похож на шаблон. Минимум 16 символов без change_me/password/admin/default/secret/qwerty/123456.\n");
    exit(1);
}

$migrator = new Migrator(Database::pdo());
$migrator->run();
$pdo = Database::pdo();
$stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$existing = $stmt->fetch();
if ($existing && getenv('FORCE') !== '1') {
    fwrite(STDOUT, "Пользователь {$username} уже существует. Обновить пароль/роль? [y/N]: ");
    $answer = strtolower(trim((string)fgets(STDIN)));
    if (!in_array($answer, ['y', 'yes', 'д', 'да'], true)) {
        fwrite(STDOUT, "Операция отменена.\n");
        exit(0);
    }
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
$sql = Database::driver() === 'pgsql'
    ? "INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :hash, :role, :active, {$now}, {$now}) ON CONFLICT (username) DO UPDATE SET password_hash = EXCLUDED.password_hash, role = EXCLUDED.role, is_active = EXCLUDED.is_active, updated_at = NOW()"
    : "INSERT OR REPLACE INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :hash, :role, :active, {$now}, {$now})";
$pdo->prepare($sql)->execute(['username' => $username, 'hash' => $hash, 'role' => $role, 'active' => $active]);
echo "Пользователь сохранён: {$username} ({$role}, " . ($active ? 'активен' : 'отключён') . ")\n";
