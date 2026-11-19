<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseStatus};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\{IdentityPolicy,TwoFactorManager};
use PDO;

/** Probe uses its own nonpersistent session, never modifies ordinary request/worker DB settings. */
final class Readiness
{
    public function __construct(private readonly Application $app) {}
    public function factory(): ConnectionFactory
    {
        $cfg=clone $this->app->config();
        foreach (['connect_attempts'=>'1','connect_timeout'=>'2','statement_timeout_ms'=>'1500','lock_timeout_ms'=>'1000','tcp_user_timeout_ms'=>'3000'] as $key=>$value) $cfg->set('database.'.$key,$value);
        return new ConnectionFactory($cfg);
    }
    public function check(?PDO $pdo=null): array
    {
        $cfg=$this->app->config();
        try {
            if (!$this->app->container()->get(Diagnostics::class)->ok()) return ['status'=>'down'];
            if ($cfg->get('app.env')==='production' && parse_url($cfg->get('app.url'),PHP_URL_SCHEME)!=='https') return ['status'=>'down'];
            if (in_array(false,\Cajeer\Logs\Security\SecurityPosture::configuration($cfg),true)) return ['status'=>'down'];
            if ($cfg->get('app.env')==='production' && $cfg->get('observability.prometheus_enabled') && $cfg->get('operations.metrics_token')==='') return ['status'=>'down'];
            if (($cfg->get('security.require_admin_totp')||IdentityPolicy::fromConfig($cfg)->requiresEnrollmentPolicy())&&!TwoFactorManager::keyValid($cfg->get('security.totp_key'))) return ['status'=>'down'];
            $pdo??=$this->factory()->make('pgsql');
            return ['status'=>$this->app->container()->get(DatabaseStatus::class)->check(true,false,$pdo)['status']];
        } catch (\Throwable) { return ['status'=>'down']; }
    }
}
