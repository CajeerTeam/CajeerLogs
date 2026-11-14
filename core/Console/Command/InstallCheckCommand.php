<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\Diagnostics;

final class InstallCheckCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        $checks = $this->app->container()->get(Diagnostics::class)->checks();
        echo 'CajeerLogs ' . $this->app->version() . " runtime checks\n";
        foreach ($checks as $name => $passed) {
            echo ($passed ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
        }
        $db = $this->app->container()->get(\Cajeer\Logs\Database\DatabaseStatus::class)->check(false);
        echo ($db['status'] === 'ok' ? '[OK] ' : '[FAIL] ') . 'PostgreSQL preflight: ' . json_encode($db, JSON_THROW_ON_ERROR) . PHP_EOL;
        $argon = defined('PASSWORD_ARGON2ID');
        echo ($argon ? '[OK] ' : '[FAIL] ') . 'Argon2id' . PHP_EOL;
        $key=\Cajeer\Logs\Security\TwoFactorManager::keyValid($this->app->config()->get('security.totp_key'));
        echo ($key ? '[OK] ' : '[INFO] ') . 'TOTP encryption key (installer generates legacy fallback; required before enrollment)' . PHP_EOL;
        return in_array(false, $checks, true) || $db['status'] !== 'ok' || !$argon ? 1 : 0;
    }
}
