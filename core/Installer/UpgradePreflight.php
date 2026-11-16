<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,DatabaseStatus,MigrationRunner};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Operations\BackupManager;

/** Operator preflight only: validates an actual backup and current migration history. */
final class UpgradePreflight
{
    public function __construct(private readonly Application $app) {}

    public function check(string $backupId): array
    {
        $runner=$this->app->container()->get(MigrationRunner::class);
        $status=$runner->status(false);
        if ($status['integrity']!=='verified') throw new DatabaseException('Upgrade requires a verified existing schema without drift.');
        $backup=(new BackupManager($this->app))->verify($backupId);
        $schema=$this->app->container()->get(ConnectionFactory::class)->schema();
        if ($backup['schema']!==$schema || !BackupManager::compatibleRelease($backup['version']??null,$this->app->version()) || BackupManager::expectedPending($backup['version'])!==$status['pending']) {
            throw new DatabaseException('Backup schema or release migration history does not match upgrade source.');
        }
        $db=$this->app->container()->get(DatabaseStatus::class)->check($status['pending']===[]);
        if ($db['status']!=='ok') throw new DatabaseException('Upgrade database preflight failed.');
        return ['status'=>'ok','version'=>$this->app->version(),'backup_id'=>$backupId,'backup_version'=>$backup['version'],
            'backup_sha256'=>$backup['sha256'],'pending'=>$status['pending'],'schema_verified'=>true,'backup_verified'=>true];
    }
}
