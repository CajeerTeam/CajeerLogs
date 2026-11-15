<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\DatabaseStatus;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Kernel\Application;
use PDO;

final class InstallationStatus
{
    public function __construct(private readonly Application $app) {}

    public function report(): array
    {
        $path = $this->app->environmentPath();
        $report = ['version'=>$this->app->version(),'configuration_present'=>is_file($path) && !is_link($path),'state'=>'database_unavailable'];
        try {
            $pdo = $this->app->container()->get(PDO::class);
            $report['state']='schema_unavailable';
            $runner = $this->app->container()->get(MigrationRunner::class);
            $status = $runner->status(false);
            $report['migrations'] = ['applied'=>count($status['applied']),'pending'=>$status['pending'],'integrity'=>$status['integrity']];
            if ($status['integrity'] === 'drift') { $report['state']='schema_drift'; return $report; }
            if ($status['pending'] !== [] || $status['integrity'] !== 'verified') { $report['state']='migration_required'; return $report; }
            $guard = $runner->guard();
            try {
                $report['active_administrators'] = (int)$pdo->query("SELECT count(*) FROM users WHERE role='administrator' AND blocked_at IS NULL")->fetchColumn();
                $report['administrator_count'] = (int)$pdo->query("SELECT count(*) FROM users WHERE role='administrator'")->fetchColumn();
                $report['completion_recorded'] = $pdo->query("SELECT EXISTS(SELECT 1 FROM settings WHERE key='installation.completed')")->fetchColumn() === true;
                if ($report['active_administrators'] === 0) { $report['state']=$report['administrator_count']>0 || $report['completion_recorded']?'administrator_recovery_required':'administrator_required'; return $report; }
                $config = $this->app->config();
                $healthy = $this->app->container()->get(\Cajeer\Logs\Observability\Diagnostics::class)->ok()
                    && ($config->get('app.env') !== 'production' || parse_url($config->get('app.url'), PHP_URL_SCHEME) === 'https')
                    && (!($config->get('security.require_admin_totp') || \Cajeer\Logs\Security\IdentityPolicy::fromConfig($config)->requiresEnrollmentPolicy()) || \Cajeer\Logs\Security\TwoFactorManager::keyValid($config->get('security.totp_key')))
                    && $this->app->container()->get(DatabaseStatus::class)->check()['status'] === 'ok';
                $report['state'] = $healthy?'ready':'configuration_or_permissions_required';
                return $report;
            } finally { $guard->release(); }
        } catch (\Throwable $e) {
            $report['error_category']=\Cajeer\Logs\Database\DatabaseFailure::category($e);
            return $report;
        }
    }
}
