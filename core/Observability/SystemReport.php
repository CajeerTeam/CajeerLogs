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
        $queue=null;
        if ($db['status']==='ok') {
            try { $queue=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->counts(); } catch (\Throwable) {}
        }
        return [
            'service' => $this->app->config()->get('app.name'),
            'version' => $this->app->version(),
            'scope' => 'runtime+database',
            'status' => in_array(false, $checks, true) || $db['status'] !== 'ok' ? 'down' : 'ok',
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
        ];
    }
}
