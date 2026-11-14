<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Database\DatabaseStatus;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Kernel\Application;
use PDO;

final class Installer
{
    public function __construct(private readonly Application $app) {}

    public function run(string $email, string $password): array
    {
        $email = UserRepository::email($email);
        AdministratorPassword::validate($password);
        (new InstallPreflight($this->app))->assertReady();
        $locks = [];
        try {
            // The storage lock also coordinates with older installers. The config lock
            // prevents two different checkouts sharing a single external .env from racing.
            foreach ([$this->app->basePath('storage/.install.lock'),$this->app->environmentPath().'.install.lock'] as $path) {
                $locks[] = self::lockFile($path);
            }
            $pdo = $this->app->container()->get(PDO::class);
            $factory = $this->app->container()->get(ConnectionFactory::class);
            $schema = $factory->schema();
            // Separate session-level key covers configuration, migration and bootstrap across hosts.
            $key = 'cajeerlogs:install-session:' . $schema;
            $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(:key,0))');
            $stmt->execute(['key'=>$key]); $held = $stmt->fetchColumn() === true; $stmt->closeCursor();
            if (!$held) { throw new DatabaseException('Another database installation is running.'); }
            try {
                $runner = $this->app->container()->get(MigrationRunner::class);
                $state = $runner->status(false);
                $current = $state['integrity'] === 'verified' && $state['pending'] === [];
                // A completed split-role install can be verified without DDL credentials.
                if (!$current) {
                    $preflight = $this->app->container()->get(DatabaseStatus::class)->check(false);
                    if ($preflight['status'] !== 'ok') { throw new DatabaseException('PostgreSQL installation preflight failed; run install:check.'); }
                }
                if ($pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'off' || $pdo->query('SELECT pg_is_in_recovery()')->fetchColumn() === true || $pdo->query('SHOW fsync')->fetchColumn() !== 'on') {
                    throw new DatabaseException('Installation requires a writable PostgreSQL primary with fsync enabled.');
                }
                $users = $this->app->container()->get(UserRepository::class);
                // Existing credentials must match before touching the persistent configuration.
                if ($pdo->query("SELECT to_regclass('users') IS NOT NULL")->fetchColumn() === true) {
                    $users->verifyInstallerAdministrator($email, $password);
                }
                (new EnvironmentWriter($this->app))->write(function (array $values) use ($pdo): void {
                    // Do not persist a key that cannot decrypt existing enrollments.
                    if ($pdo->query("SELECT to_regclass('user_totp') IS NOT NULL")->fetchColumn() !== true) { return; }
                    $key = ($values['SECURITY_TOTP_KEY'] ?? '') ?: $values['SECURITY_HMAC_SECRET'];
                    $manager = new \Cajeer\Logs\Security\TwoFactorManager($pdo,$key);
                    $rows = $pdo->query('SELECT user_id,secret_ciphertext FROM user_totp WHERE pending_session IS NULL');
                    while ($row = $rows->fetch()) {
                        try { $manager->open($row['user_id'],$row['secret_ciphertext']); }
                        catch (\Throwable) { throw new DatabaseException('Installation configuration cannot decrypt an existing TOTP enrollment. Restore the original key.'); }
                    }
                    $rows->closeCursor();
                });
                $applied = $current ? [] : (new MigrationRunner($factory->make('migration'), $this->app->basePath('migrations/pgsql'), $schema, ...\Cajeer\Logs\Console\Command\MigrateCommand::limits()))->migrate();
                $guard = $this->app->container()->get(MigrationRunner::class)->guard();
                try { $created = $users->ensureFirstAdministrator($email, $password, $schema); }
                finally { $guard->release(); }
                // Reload persisted configuration, including generated keys, before claiming completion.
                $fresh = new Application($this->app->basePath()); $fresh->boot();
                $ready = $fresh->container()->get(DatabaseStatus::class)->check();
                if ($ready['status'] !== 'ok') { throw new DatabaseException('Installation saved, but readiness failed; correct configuration and rerun install or install:status.'); }
                return ['applied'=>$applied, 'administrator_created'=>$created];
            } finally {
                try { $stmt = $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:key,0))'); $stmt->execute(['key'=>$key]); $stmt->closeCursor(); } catch (\Throwable) { /* Session loss releases the lock. */ }
            }
        } finally {
            foreach (array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); }
        }
    }

    private static function lockFile(string $path)
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new DatabaseException('Invalid installer lock path.'); }
        $mask=umask(0077);
        try { $lock=fopen($path,'c'); } finally { umask($mask); }
        if ($lock===false) { throw new DatabaseException('Cannot open installer lock.'); }
        try {
            $stat=fstat($lock); $named=lstat($path);
            if ($stat===false || $named===false || ($named['mode'] & 0170000)!==0100000 || $named['ino']!==$stat['ino'] || $named['dev']!==$stat['dev']) { throw new DatabaseException('Invalid installer lock file.'); }
            if (!flock($lock,LOCK_EX|LOCK_NB)) { throw new DatabaseException('Another local installation is running.'); }
            return $lock;
        } catch (\Throwable $e) { fclose($lock); throw $e; }
    }
}
