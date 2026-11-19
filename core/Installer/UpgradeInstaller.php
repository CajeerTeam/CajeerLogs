<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Console\Command\MigrateCommand;
use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,MigrationRunner,PostgresBoolean,TransactionManager};
use Cajeer\Logs\Kernel\Application;
use PDO;

/** Apply a verified, operator-approved in-place release upgrade, or safely resume it. */
final class UpgradeInstaller
{
    public function __construct(private readonly Application $app) {}

    public function run(string $backupId): array
    {
        // Share both installer lock paths and the PostgreSQL session lock. No config or
        // schema writes are permitted before these have been acquired.
        $locks=[];
        try {
            foreach ([$this->app->basePath('storage/.install.lock'), $this->app->environmentPath().'.install.lock'] as $path) {
                $locks[]=Installer::lockFile($path);
            }
            $pdo=$this->app->container()->get(PDO::class);
            $factory=$this->app->container()->get(ConnectionFactory::class);
            $schema=$factory->schema();
            $key='cajeerlogs:install-session:'.$schema;
            $stmt=$pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(:key,0))');
            $stmt->execute(['key'=>$key]);
            $held=PostgresBoolean::required($stmt->fetchColumn()); $stmt->closeCursor();
            if (!$held) throw new DatabaseException('Another database installation or upgrade is running.');
            try {
                // Revalidate INSIDE the lock: no time-of-check/time-of-use gap.
                $proof=(new UpgradePreflight($this->app))->check($backupId, true);
                if ($pdo->query('SHOW transaction_read_only')->fetchColumn()!=='off'
                    || PostgresBoolean::required($pdo->query('SELECT pg_is_in_recovery()')->fetchColumn())
                    || $pdo->query('SHOW fsync')->fetchColumn()!=='on') {
                    throw new DatabaseException('Upgrade requires a writable PostgreSQL primary with fsync enabled.');
                }
                $applied=[];
                // A schema-identical release and a completed retry need runtime
                // credentials only. No fresh DDL connection is opened in this case.
                if ($proof['pending']!==[]) {
                    [$timeout,$batches]=MigrateCommand::limits();
                    $runner=new MigrationRunner($factory->make('migration'),$this->app->basePath('migrations/pgsql'),$schema,$timeout,$batches);
                    $applied=$runner->migrate(); // Durable per-migration journal supports retry.
                }
                $after=$this->app->container()->get(MigrationRunner::class)->status(false);
                if ($after['integrity']!=='verified' || $after['pending']!==[]) {
                    throw new DatabaseException('Upgrade did not finish with a verified schema.');
                }
                $ready=(new InstallationStatus($this->app))->report();
                if ($ready['state']!=='ready') {
                    throw new DatabaseException('Upgrade applied but service is not ready; resolve install:status and safely rerun upgrade.');
                }
                // Persistent, atomic evidence. No external token or credential is recorded.
                $recorded=(new TransactionManager($pdo))->run(function () use ($pdo,$proof,$backupId): bool {
                    $value=json_encode([
                        'version'=>$this->app->version(), 'from'=>$proof['backup_version'],
                        'backup_id'=>$backupId, 'backup_sha256'=>$proof['backup_sha256'],
                        'verified_at'=>gmdate(DATE_ATOM),
                    ], JSON_THROW_ON_ERROR);
                    $q=$pdo->prepare("INSERT INTO settings(key,value) VALUES (:key,CAST(:value AS jsonb)) ON CONFLICT(key) DO NOTHING");
                    $q->execute(['key'=>'installation.upgrade.'.$this->app->version(),'value'=>$value]);
                    if ($q->rowCount()===0) {
                        $old=$pdo->prepare('SELECT value FROM settings WHERE key=:key');
                        $old->execute(['key'=>'installation.upgrade.'.$this->app->version()]);
                        $existing=json_decode((string)$old->fetchColumn(),true);
                        if (!is_array($existing) || ($existing['from']??null)!==$proof['backup_version']
                            || ($existing['backup_id']??null)!==$backupId
                            || !is_string($existing['backup_sha256']??null)
                            || !hash_equals($existing['backup_sha256'],$proof['backup_sha256'])) {
                            throw new DatabaseException('Upgrade has already been certified against a different source backup.');
                        }
                        return false;
                    }
                    (new AuditLogger($pdo))->record('installation.upgrade_verified',[
                        'from'=>$proof['backup_version'],'to'=>$this->app->version(),
                        'backup_sha256'=>$proof['backup_sha256'],
                    ]);
                    return true;
                });
                return ['status'=>'ready','version'=>$this->app->version(),'from'=>$proof['backup_version'],
                    'backup_id'=>$backupId,'applied'=>$applied,'schema_verified'=>true,
                    'administrator_count'=>$ready['administrator_count'],'resumed'=>$proof['resuming'],'recorded'=>$recorded];
            } finally {
                try {
                    $stmt=$pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:key,0))');
                    $stmt->execute(['key'=>$key]); $stmt->closeCursor();
                } catch (\Throwable) { /* Broken sessions release advisory locks automatically. */ }
            }
        } finally {
            foreach (array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); }
        }
    }
}
