<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Kernel\Application;

final class HealthCheck
{
    public function __construct(private readonly Application $app) {}

    public function run(): array
    {
        $ok = $this->app->container()->get(Diagnostics::class)->ok();
        return ['status' => $ok ? 'ok' : 'down', 'service' => $this->app->config()->get('app.name'), 'version' => $this->app->version(), 'scope' => 'runtime'];
    }
}
