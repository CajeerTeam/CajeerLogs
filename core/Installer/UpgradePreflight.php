<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,DatabaseStatus,MigrationRunner};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Operations\BackupManager;

/** Read-only backup/host/schema verification, including safe retry-prefix validation. */
final class UpgradePreflight
{
    public function __construct(private readonly Application $app) {}

    /** A restart may only have applied a prefix of the migrations expected at backup time. */
    public static function pendingMatches(array $original, array $remaining, bool $allowPartial): bool
    {
        if ($original === $remaining) return true;
        if (!$allowPartial || count($remaining) >= count($original)) return false;
        return array_slice($original, count($original)-count($remaining)) === $remaining;
    }

    public function check(string $backupId, bool $allowPartial=false): array
    {
        // Upgrades do not rewrite .env, so its directory may be read-only, unlike
        // first installation. Still refuse an insecure/runtime-incompatible host
        // before starting potentially non-transactional migrations.
        $checks=(new InstallPreflight($this->app))->checks();
        $required=['pdo_pgsql','argon2id','https_origin','metrics_authenticated',
            'configuration_regular_file','storage_private_directory','storage_disk_space'];
        $failed=array_values(array_filter($required,static fn(string $key): bool => ($checks[$key]??false)!==true));
        if ($failed!==[]) throw new DatabaseException('Upgrade host preflight failed: '.implode(', ',$failed));
        $runner=$this->app->container()->get(MigrationRunner::class);
        $status=$runner->status(false);
        if ($status['integrity']!=='verified') throw new DatabaseException('Upgrade requires a verified existing schema without drift.');
        $backup=(new BackupManager($this->app))->verify($backupId);
        $schema=$this->app->container()->get(ConnectionFactory::class)->schema();
        if ($backup['schema']!==$schema || !BackupManager::compatibleRelease($backup['version']??null,$this->app->version()) || !self::pendingMatches(BackupManager::expectedPending($backup['version']),$status['pending'],$allowPartial)) {
            throw new DatabaseException('Backup schema or release migration history does not match upgrade source.');
        }
        $db=$this->app->container()->get(DatabaseStatus::class)->check($status['pending']===[]);
        if ($db['status']!=='ok') throw new DatabaseException('Upgrade database preflight failed.');
        return ['status'=>'ok','version'=>$this->app->version(),'backup_id'=>$backupId,'backup_version'=>$backup['version'],
            'backup_sha256'=>$backup['sha256'],'pending'=>$status['pending'],'schema_verified'=>true,'backup_verified'=>true,
            'resuming'=>$allowPartial && BackupManager::expectedPending($backup['version'])!==$status['pending']];
    }
}
