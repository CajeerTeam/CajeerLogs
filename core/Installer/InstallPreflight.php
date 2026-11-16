<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\Diagnostics;

final class InstallPreflight
{
    public function __construct(private readonly Application $app) {}

    public function checks(): array
    {
        $path = $this->app->environmentPath();
        $directory = dirname($path);
        $free = is_dir($directory) ? disk_free_space($directory) : false;
        $storage = $this->app->basePath('storage');
        $storageFree = is_dir($storage) ? disk_free_space($storage) : false;
        return $this->app->container()->get(Diagnostics::class)->checks() + [
            'pdo_pgsql' => in_array('pgsql', \PDO::getAvailableDrivers(), true),
            'argon2id' => defined('PASSWORD_ARGON2ID'),
            'https_origin' => $this->app->config()->get('app.env') !== 'production' || parse_url($this->app->config()->get('app.url'), PHP_URL_SCHEME) === 'https',
            'configuration_directory_writable' => SecureFile::directory($directory),
            'configuration_regular_file' => SecureFile::privateFile($path,true),
            'configuration_disk_space' => $free !== false && $free >= 1048576,
            'storage_private_directory' => SecureFile::directory($storage),
            'storage_disk_space' => $storageFree !== false && $storageFree >= 1048576,
        ];
    }

    /** No configuration writes, migrations, bootstrap or persistent locks. */
    public function report(): array
    {
        $checks=$this->checks();
        $database=['status'=>'down']; $migration=null;
        try {
            $migration=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->status(false);
            $current=$migration['integrity']==='verified' && $migration['pending']===[];
            $database=$this->app->container()->get(\Cajeer\Logs\Database\DatabaseStatus::class)->check($current,true);
            $checks['migration_history_safe']=$migration['integrity']!=='drift';
        } catch (\Throwable $error) {
            $database=['status'=>'down','category'=>\Cajeer\Logs\Database\DatabaseFailure::category($error)];
            $checks['migration_history_safe']=false;
        }
        $checks['database_ready']=$database['status']==='ok';
        return ['status'=>in_array(false,$checks,true)?'failed':'ok','checks'=>$checks,'database'=>$database,
            'migrations'=>$migration===null?null:['integrity'=>$migration['integrity'],'applied'=>count($migration['applied']),'pending'=>$migration['pending']]];
    }

    public function assertReady(): void
    {
        $failed = array_keys(array_filter($this->checks(), static fn(bool $ok): bool => !$ok));
        if ($failed !== []) { throw new DatabaseException('Installation preflight failed: ' . implode(', ', $failed)); }
    }
}
