<?php
declare(strict_types=1);

namespace CajeerLogs;

use PDO;
use Throwable;

final class Auth
{
    private static bool $started = false;

    /** @var array<string,string[]> */
    private const ROLE_PERMISSIONS = [
        'admin' => ['*'],
        'operator' => ['logs.view','logs.export','bots.view','bots.manage','incidents.view','incidents.manage','alerts.view','alerts.manage','sites.view','sites.manage','cron.view','system.view'],
        'security' => ['logs.view','logs.export','audit.view','incidents.view','incidents.manage','alerts.view','system.view'],
        'viewer' => ['logs.view','bots.view','incidents.view','sites.view','system.view'],
        'emergency_admin' => ['*'],
    ];

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('cajeer_logs_session');
            session_start();
        }
        self::$started = true;
    }

    public static function requireLogin(): void
    {
        self::start();
        if (self::user()) {
            return;
        }
        if (self::acceptsJson()) {
            Response::json(['ok' => false, 'error' => 'unauthorized', 'message' => 'Требуется вход в систему.'], 401);
            exit;
        }
        Response::redirect('/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (self::can($permission)) {
            return;
        }
        if (self::acceptsJson()) {
            Response::json(['ok' => false, 'error' => 'forbidden', 'message' => 'Недостаточно прав.'], 403);
            exit;
        }
        Response::html(View::layout('Недостаточно прав', '<h1>Недостаточно прав</h1><section class="panel"><p>У текущей роли нет доступа к этому действию.</p><a class="button ghost" href="/">На панель</a></section>'), 403);
        exit;
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function role(): string
    {
        $user = self::user();
        return $user ? (string)($user['role'] ?? 'viewer') : 'guest';
    }

    public static function user(): ?array
    {
        self::start();
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function username(): string
    {
        $user = self::user();
        if ($user && isset($user['username'])) {
            return (string)$user['username'];
        }
        return (string)Env::get('LOGS_WEB_BASIC_USER', 'admin');
    }

    public static function attempt(PDO $pdo, string $username, string $password): bool
    {
        self::start();
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        try {
            if (self::isLockedOut($pdo, $username)) {
                return false;
            }
            self::seedAdminIfEmpty($pdo);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, (string)$user['password_hash'])) {
                self::clearLoginAttempts($pdo, $username);
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'username' => (string)$user['username'],
                    'role' => (string)($user['role'] ?? 'admin'),
                    'emergency' => false,
                ];
                $sql = Database::driver() === 'pgsql'
                    ? 'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id'
                    : 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $pdo->prepare($sql)->execute(['id' => (int)$user['id']]);
                return true;
            }
            self::recordFailedLogin($pdo, $username);
        } catch (Throwable $e) {
            Logger::error('Database login failed; emergency fallback may be used', ['exception' => $e]);
        }

        return self::attemptEmergencyFallback($username, $password);
    }

    public static function isEmergencyUser(): bool
    {
        $user = self::user();
        return $user !== null && !empty($user['emergency']);
    }

    private static function attemptEmergencyFallback(string $username, string $password): bool
    {
        if (!Env::bool('LOGS_ENV_FALLBACK_LOGIN', false)) {
            return false;
        }
        $expectedUser = (string)Env::get('LOGS_WEB_BASIC_USER', 'admin');
        $expectedPassword = (string)Env::get('LOGS_WEB_BASIC_PASSWORD', 'admin');
        if (!hash_equals($expectedUser, $username) || !hash_equals($expectedPassword, $password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => 0,
            'username' => $username,
            'role' => 'emergency_admin',
            'emergency' => true,
        ];
        Logger::warning('Emergency .env login used', ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function seedAdminIfEmpty(PDO $pdo): void
    {
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                return;
            }
            $username = trim((string)Env::get('LOGS_WEB_BASIC_USER', 'admin'));
            $password = (string)Env::get('LOGS_WEB_BASIC_PASSWORD', '');
            if ($username === '' || self::weakBootstrapPassword($password)) {
                Logger::warning('Первый администратор не создан: LOGS_WEB_BASIC_PASSWORD пустой или похож на шаблон. Используйте php bin/make-user.php.');
                return;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :password_hash, 'admin', 1, {$now}, {$now})");
            $stmt->execute(['username' => $username, 'password_hash' => $hash]);
        } catch (Throwable $e) {
            Logger::error('Admin seed failed', ['exception' => $e]);
        }
    }


    public static function weakBootstrapPassword(string $password): bool
    {
        $password = trim($password);
        if (strlen($password) < 16) {
            return true;
        }
        $lower = strtolower($password);
        foreach (['change_me', 'changeme', 'password', 'admin', 'default', 'secret', 'qwerty', '123456'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function isLockedOut(PDO $pdo, string $username): bool
    {
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
            if (Database::driver() === 'pgsql') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = :username AND ip = :ip AND success = 0 AND attempted_at >= NOW() - INTERVAL '10 minutes'");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = :username AND ip = :ip AND success = 0 AND datetime(attempted_at) >= datetime('now', '-10 minutes')");
            }
            $stmt->execute(['username' => $username, 'ip' => $ip]);
            return (int)$stmt->fetchColumn() >= Env::int('LOGIN_LOCKOUT_ATTEMPTS', 5);
        } catch (Throwable) {
            return false;
        }
    }

    private static function recordFailedLogin(PDO $pdo, string $username): void
    {
        try {
            $sql = 'INSERT INTO login_attempts (username, ip, user_agent, success, attempted_at) VALUES (:username, :ip, :ua, 0, ' . (Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP') . ')';
            $pdo->prepare($sql)->execute([
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable) {}
    }

    private static function clearLoginAttempts(PDO $pdo, string $username): void
    {
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
            $pdo->prepare('DELETE FROM login_attempts WHERE username = :username AND ip = :ip')->execute(['username' => $username, 'ip' => $ip]);
        } catch (Throwable) {}
    }

    private static function acceptsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return is_string($accept) && str_contains($accept, 'application/json');
    }
}
