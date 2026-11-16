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
        $backup=null; try { $backup=(new \Cajeer\Logs\Operations\BackupOperations($this->app))->status(); } catch (\Throwable) {}
        return [
            'service' => $this->app->config()->get('app.name'),
            'version' => $this->app->version(),
            'scope' => 'runtime+database',
            'status' => ($this->app->config()->get('app.env') === 'production' && parse_url($this->app->config()->get('app.url'), PHP_URL_SCHEME) !== 'https') || in_array(false, $checks, true) || $db['status'] !== 'ok' || (($this->app->config()->get('security.require_admin_totp') || \Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->requiresEnrollmentPolicy()) && !\Cajeer\Logs\Security\TwoFactorManager::keyValid($this->app->config()->get('security.totp_key'))) ? 'down' : 'ok',
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
            'operations' => $operations,
            'backup' => $backup,
            'log_repository_metrics_local' => \Cajeer\Logs\Log\LogRepository::metrics(),
            'transaction_metrics_local' => \Cajeer\Logs\Database\TransactionManager::metrics(),
            'storage_free_bytes' => is_dir($this->app->basePath('storage')) ? (disk_free_space($this->app->basePath('storage')) ?: null) : null,
            'deployment_security' => (new \Cajeer\Logs\Security\DeploymentSecurity($this->app->config()))->snapshot(),
            'rate_limits' => \Cajeer\Logs\Security\RateLimitPolicy::fromConfig($this->app->config())->snapshot(),
            'security' => ['admin_totp_required'=>$this->app->config()->get('security.require_admin_totp'), 'required_totp_roles'=>\Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->roles, 'required_totp_permissions'=>\Cajeer\Logs\Security\IdentityPolicy::fromConfig($this->app->config())->permissions, 'session_idle_seconds'=>$this->app->config()->get('security.session_idle_seconds'), 'reauth_seconds'=>$this->app->config()->get('security.reauth_seconds'), 'totp_key_ready'=>\Cajeer\Logs\Security\TwoFactorManager::keyValid($this->app->config()->get('security.totp_key')), 'metrics_protected'=>$this->app->config()->get('operations.metrics_token')!==''],
        ];
    }
}
