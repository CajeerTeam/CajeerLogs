<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Kernel\Application;

final class SystemReport
{
    public function __construct(private readonly Application $app) {}

    public function build(): array
    {
        $checks = $this->app->container()->get(Diagnostics::class)->checks();
        $db = $this->app->container()->get(\Cajeer\Logs\Database\DatabaseStatus::class)->check();
        $queue=null; $operations=null;
        if ($db['status']==='ok') {
            try { $queue=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->counts(); } catch (\Throwable) {}
        }
        try { if ($db['status']==='ok') { $operations=(new OperationsMetrics($this->app))->snapshot(); } } catch (\Throwable) {}
        $retention=null; if ($db['status']==='ok') {try { $history=(new \Cajeer\Logs\Lifecycle\Retention($this->app->container()->get(\PDO::class)))->history(10); $retention=['scheduler_enabled'=>$this->app->config()->get('operations.retention_scheduler_enabled'),'window_utc'=>$this->app->config()->get('operations.retention_window_utc'),'window_open'=>\Cajeer\Logs\Lifecycle\Retention::windowOpen($this->app->config()->get('operations.retention_window_utc')),'batch_size'=>$this->app->config()->get('operations.retention_batch_size'),'timeout_ms'=>$this->app->config()->get('operations.retention_timeout_ms'),'history'=>$history]; } catch (\Throwable) {}}
        $backup=null; try { $backup=(new \Cajeer\Logs\Operations\BackupOperations($this->app))->status(); } catch (\Throwable) {}
        $free=is_dir($this->app->basePath('storage'))?@disk_free_space($this->app->basePath('storage')):false;
        $threshold=$this->app->config()->get('operations.storage_warn_bytes');
        return [
            'service' => $this->app->config()->get('app.name'),
            'version' => $this->app->version(),
            'scope' => 'runtime+database',
            'status' => ($this->app->config()->get('app.env') === 'production' && $this->app->config()->get('observability.prometheus_enabled') && $this->app->config()->get('operations.metrics_token') === '') || ($this->app->config()->get('app.env') === 'production' && parse_url($this->app->config()->get('app.url'), PHP_URL_SCHEME) !== 'https') || in_array(false, $checks, true) || $db['status'] !== 'ok' || (($this->app->config()->get('security.require_admin_totp') || \Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->requiresEnrollmentPolicy()) && !\Cajeer\Logs\Security\TwoFactorManager::keyValid($this->app->config()->get('security.totp_key'))) ? 'down' : 'ok',
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'environment' => $this->app->config()->get('app.env'),
            'timezone' => date_default_timezone_get(),
            'memory_limit' => ini_get('memory_limit'),
            'pdo_drivers' => class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [],
            'checks' => $checks,
            'database' => $db,
            'queue' => $queue,
            'queue_dispatch' => $db['status']==='ok'?(new \Cajeer\Logs\Queue\QueueOperations($this->app->container()->get(\PDO::class)))->status():null,
            'operations' => $operations,
            'backup' => $backup,
            'queue_workers' => (new \Cajeer\Logs\Queue\WorkerRegistry($this->app->basePath('storage/cache')))->snapshot(),
            'queue_project_summary' => $operations===null?null:array_intersect_key($operations['database'],array_flip(['queue_project_projects','queue_project_pending','queue_project_running','queue_project_dispatched','queue_project_rejected'])),
            'queue_backpressure_policy' => \Cajeer\Logs\Queue\BackpressurePolicy::fromConfig($this->app->config())->limits(),
            'queue_retry_policy' => \Cajeer\Logs\Queue\RetryPolicy::fromConfig($this->app->config())->values,
            'queue_worker_policy' => \Cajeer\Logs\Queue\WorkerPolicy::fromConfig($this->app->config())->values,
            'workers' => (new WorkerHeartbeat($this->app->basePath('storage/cache')))->snapshot(),
            'retention' => $retention,
            'log_repository_metrics_local' => \Cajeer\Logs\Log\LogRepository::metrics(),
            'transaction_metrics_local' => \Cajeer\Logs\Database\TransactionManager::metrics(),
            'storage_free_bytes' => $free===false?null:(int)$free,
            'storage_warning' => $free===false?null:($threshold>0&&$free<$threshold),
            'storage_warn_bytes' => $threshold,
            'deployment_security' => (new \Cajeer\Logs\Security\DeploymentSecurity($this->app->config()))->snapshot(),
            'rate_limits' => \Cajeer\Logs\Security\RateLimitPolicy::fromConfig($this->app->config())->snapshot(),
            'security' => ['admin_totp_required'=>$this->app->config()->get('security.require_admin_totp'), 'required_totp_roles'=>\Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->roles, 'required_totp_permissions'=>\Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->permissions, 'session_idle_seconds'=>$this->app->config()->get('security.session_idle_seconds'), 'reauth_seconds'=>$this->app->config()->get('security.reauth_seconds'), 'totp_key_ready'=>\Cajeer\Logs\Security\TwoFactorManager::keyValid($this->app->config()->get('security.totp_key')), 'metrics_protected'=>$this->app->config()->get('operations.metrics_token')!==''],
        ];
    }
}
