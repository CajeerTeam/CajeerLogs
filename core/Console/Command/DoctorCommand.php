<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;

final class DoctorCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        $result = (new InstallCheckCommand($this->app))->handle();
        $health = $this->app->handle(new Request('GET', $this->app->config()->get('api.prefix') . '/health'));
        $wrongMethod = $this->app->handle(new Request('POST', '/health'));
        $missing = $this->app->handle(new Request('GET', '/__doctor_missing_' . bin2hex(random_bytes(8))));
        $ok = $health->status === 200 && $wrongMethod->status === 405 && $missing->status === 404;
        echo ($ok ? '[OK] ' : '[FAIL] ') . "HTTP dispatch (health, method rejection, unknown path)\n";
        return $result === 0 && $ok && (new DbDoctorCommand($this->app))->handle() === 0 ? 0 : 1;
    }
}
