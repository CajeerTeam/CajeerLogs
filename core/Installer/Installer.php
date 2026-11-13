<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\Diagnostics;
use PDO;

final class Installer
{
    public function __construct(private readonly Application $app) {}

    public function run(string $email, string $password): array
    {
        $email = UserRepository::email($email);
        if (strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\0") || !defined('PASSWORD_ARGON2ID')) { throw new DatabaseException('Administrator needs a 12–1024 byte password and PHP Argon2id support.'); }
        if (!$this->app->container()->get(Diagnostics::class)->ok()) { throw new DatabaseException('Runtime preflight failed; run install:check.'); }
        $path = $this->app->basePath('.env');
        if (is_link($path)) { throw new DatabaseException('Refusing to replace a symlinked .env file.'); }
        $lockPath = $this->app->basePath('storage/.install.lock');
        if (is_link($lockPath)) { throw new DatabaseException('Invalid installer lock path.'); }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) { throw new DatabaseException('Cannot open installer lock.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new DatabaseException('Another local installation is running.'); }
            // Connection is verified before persisting configuration or creating an administrator.
            $this->app->container()->get(PDO::class);
            $factory = $this->app->container()->get(ConnectionFactory::class);
            (new EnvironmentWriter($this->app))->write();
            $applied = $this->app->container()->get(MigrationRunner::class)->migrate();
            $created = $this->app->container()->get(UserRepository::class)->ensureFirstAdministrator($email, $password, $factory->schema());
            return ['applied' => $applied, 'administrator_created' => $created];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

}
