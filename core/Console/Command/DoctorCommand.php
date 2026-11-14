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
        $urlOk = $this->app->config()->get('app.env') !== 'production' || parse_url($this->app->config()->get('app.url'), PHP_URL_SCHEME) === 'https';
        echo ($urlOk ? '[OK] ' : '[FAIL] ') . "Production HTTPS origin\n";
        // Runtime doctor must work with read-only configuration and restricted runtime roles.
        $checks = $this->app->container()->get(\Cajeer\Logs\Observability\Diagnostics::class)->checks();
        foreach ($checks as $name=>$passed) { echo ($passed?'[OK] ':'[FAIL] ').$name.PHP_EOL; }
        $result = !in_array(false,$checks,true) && in_array('pgsql',\PDO::getAvailableDrivers(),true) && defined('PASSWORD_ARGON2ID') ? 0 : 1;
        $health = $this->app->handle(new Request('GET', $this->app->config()->get('api.prefix') . '/health'));
        $wrongMethod = $this->app->handle(new Request('POST', '/health'));
        $missing = $this->app->handle(new Request('GET', '/__doctor_missing_' . bin2hex(random_bytes(8))));
        $ok = $health->status === 200 && $wrongMethod->status === 405 && $missing->status === 404;
        echo ($ok ? '[OK] ' : '[FAIL] ') . "HTTP dispatch (health, method rejection, unknown path)\n";
        return $urlOk && $result === 0 && $ok && (new DbDoctorCommand($this->app))->handle() === 0 ? 0 : 1;
    }
}
