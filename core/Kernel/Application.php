<?php
declare(strict_types=1);

namespace Cajeer\Logs\Kernel;

use Cajeer\Logs\Api\Controller\HealthController;
use Cajeer\Logs\Api\Controller\IdentityController;
use Cajeer\Logs\Api\Controller\IngestController;
use Cajeer\Logs\Api\Controller\LogsController;
use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Http\ErrorHandler;
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Database\ConnectionFactoryInterface;
use Cajeer\Logs\Database\ConnectionManager;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Database\DatabaseStatus;
use Cajeer\Logs\Database\Repository\UserRepository;
use Cajeer\Logs\Database\Repository\ProjectRepository;
use Cajeer\Logs\Database\Repository\SourceRepository;
use Cajeer\Logs\Database\Repository\TokenRepository;
use Cajeer\Logs\Http\JsonResponse;
use PDO;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Http\Response;
use Cajeer\Logs\Http\Router;
use Cajeer\Logs\Observability\Diagnostics;
use Cajeer\Logs\Observability\HealthCheck;
use Cajeer\Logs\Observability\PrometheusExporter;
use Cajeer\Logs\Observability\SystemReport;

final class Application
{
    private ServiceContainer $container;
    private bool $booted = false;
    private ?string $version = null;

    public function __construct(private readonly string $basePath)
    {
        $this->container = new ServiceContainer();
    }

    public function basePath(string $path = ''): string
    {
        return rtrim($this->basePath . '/' . ltrim($path, '/'), '/');
    }

    public function environmentPath(): string
    {
        $path = getenv('CAJEER_ENV_FILE');
        if ($path === false || $path === '') { return $this->basePath('.env'); }
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || basename($path) !== '.env' || is_link($path)) {
            throw new \RuntimeException('Invalid CAJEER_ENV_FILE (absolute .env path required, no symlink).');
        }
        $directory = realpath(dirname($path));
        $public = realpath($this->basePath('public'));
        if ($directory === false || ($public !== false && ($directory === $public || str_starts_with($directory, $public . '/')))) {
            throw new \RuntimeException('Invalid CAJEER_ENV_FILE directory.');
        }
        return $directory . '/.env';
    }

    public function version(): string { return $this->version ??= Version::read($this->basePath); }
    public function container(): ServiceContainer { return $this->container; }

    public function boot(): void
    {
        if ($this->booted) { return; }
        if (PHP_VERSION_ID < 80400) { throw new \RuntimeException('PHP 8.4 or newer is required.'); }
        $GLOBALS['cajeer_environment'] = Environment::load($this->environmentPath());
        $items = [];
        $files = glob($this->basePath('config/*.php'));
        if ($files === false || $files === []) { throw new \RuntimeException('Configuration files are missing.'); }
        foreach ($files as $file) {
            $data = require $file;
            if (!is_array($data)) { throw new \RuntimeException('Configuration must return an array.'); }
            $items[basename($file, '.php')] = $data;
        }
        $config = new ConfigRepository($items);
        $config->set('app.version', $this->version());
        $this->validate($config);
        date_default_timezone_set($config->get('app.timezone'));
        $this->container->set('app', $this);
        $this->container->set(self::class, $this);
        $this->container->set('config', $config);
        $this->container->set(ConfigRepository::class, $config);
        $this->container->singleton(ConnectionFactory::class, fn(): ConnectionFactory => new ConnectionFactory($config));
        $this->container->singleton(ConnectionFactoryInterface::class, fn(): ConnectionFactory => $this->container->get(ConnectionFactory::class));
        $this->container->singleton(ConnectionManager::class, fn(): ConnectionManager => new ConnectionManager($this->container->get(ConnectionFactoryInterface::class)));
        $this->container->singleton(PDO::class, fn(): PDO => $this->container->get(ConnectionManager::class)->connection());
        $this->container->singleton(\Cajeer\Logs\Queue\PostgresQueueDriver::class, fn(): \Cajeer\Logs\Queue\PostgresQueueDriver => new \Cajeer\Logs\Queue\PostgresQueueDriver($this->container->get(PDO::class), $config->get('queue.max_jobs')));
        $this->container->singleton(MigrationRunner::class, fn(): MigrationRunner => new MigrationRunner($this->container->get(PDO::class), $this->basePath('migrations/pgsql'), $this->container->get(ConnectionFactory::class)->schema()));
        $this->container->singleton(DatabaseStatus::class, fn(): DatabaseStatus => new DatabaseStatus($this));
        $this->container->singleton(UserRepository::class, fn(): UserRepository => new UserRepository($this->container->get(PDO::class)));
        $this->container->singleton(ProjectRepository::class, fn(): ProjectRepository => new ProjectRepository($this->container->get(PDO::class)));
        $this->container->singleton(SourceRepository::class, fn(): SourceRepository => new SourceRepository($this->container->get(PDO::class)));
        $this->container->singleton(TokenRepository::class, fn(): TokenRepository => new TokenRepository($this->container->get(PDO::class)));
        $this->container->singleton(Diagnostics::class, fn(): Diagnostics => new Diagnostics($this));
        $this->container->singleton(HealthCheck::class, fn(): HealthCheck => new HealthCheck($this));
        $this->container->singleton(SystemReport::class, fn(): SystemReport => new SystemReport($this));
        $this->container->singleton(PrometheusExporter::class, fn(): PrometheusExporter => new PrometheusExporter($this));
        $this->container->singleton(\Cajeer\Logs\Log\IngestService::class, fn()=>new \Cajeer\Logs\Log\IngestService($this));
        $this->container->singleton(Router::class, fn(): Router => $this->routes($config));
        $this->booted = true;
    }

    private function validate(ConfigRepository $config): void
    {
        foreach (['app.name', 'app.env', 'app.locale', 'app.timezone', 'app.url', 'api.prefix', 'api.openapi', 'observability.prometheus_path'] as $key) {
            $value = $config->get($key);
            if (!is_string($value) || trim($value) === '') { throw new \RuntimeException("Invalid configuration: $key"); }
        }
        if (!in_array($config->get('app.env'), ['production', 'local', 'development', 'test'], true)) {
            throw new \RuntimeException('Invalid APP_ENV.');
        }
        foreach (['app.debug', 'observability.prometheus_enabled', 'ingest.require_hmac', 'security.require_admin_totp'] as $key) {
            if (!is_bool($config->get($key))) { throw new \RuntimeException("Invalid boolean configuration: $key"); }
        }
        if ($config->get('app.env') === 'production' && $config->get('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }
        if (!in_array($config->get('app.timezone'), \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \RuntimeException('Invalid APP_TIMEZONE.');
        }
        $url = parse_url($config->get('app.url'));
        if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true) || !filter_var($config->get('app.url'), FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Invalid APP_URL.');
        }
        if ($config->get('operations.metrics_token')!=='' && !preg_match('/^[A-Za-z0-9._~-]{32,256}$/D',$config->get('operations.metrics_token'))) { throw new \RuntimeException('Invalid PROMETHEUS_TOKEN (32–256 URL-safe characters).'); }
        $limit = filter_var($config->get('app.max_body_bytes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 16777216]]);
        if ($limit === false) { throw new \RuntimeException('APP_MAX_BODY_BYTES must be 1..16777216.'); }
        $config->set('app.max_body_bytes', $limit);
        $rate = !is_bool($config->get('api.rate_limit')) ? filter_var($config->get('api.rate_limit'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100000]]) : false;
        if ($rate === false) { throw new \RuntimeException('Invalid API_RATE_LIMIT_PER_MINUTE.'); }
        $config->set('api.rate_limit', $rate);
        foreach (['api.search_timeout_ms'=>[100,60000], 'ingest.idempotency_seconds'=>[86400,2592000], 'ingest.max_inflight'=>[0,128], 'ingest.budget_ms'=>[100,120000], 'ingest.max_event_age_seconds'=>[0,315576000], 'ingest.max_event_future_seconds'=>[0,315576000]] as $key=>[$min,$max]) {
            $value=is_bool($config->get($key)) ? false : filter_var($config->get($key),FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if ($value===false) throw new \RuntimeException('Invalid configuration: '.$key);
            $config->set($key,$value);
        }
        $queueMax=filter_var($config->get('queue.max_jobs'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($queueMax===false) { throw new \RuntimeException('Invalid QUEUE_MAX_JOBS.'); }
        $config->set('queue.max_jobs',$queueMax);
        foreach (['api.prefix', 'observability.prometheus_path'] as $key) {
            if (!preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $config->get($key))) {
                throw new \RuntimeException("Invalid route configuration: $key");
            }
        }
        if ($config->get('api.prefix') === '/admin' || str_starts_with($config->get('api.prefix'), '/admin/')) {
            throw new \RuntimeException('API prefix conflicts with the admin panel.');
        }
        if (in_array($config->get('observability.prometheus_path'), ['/health', '/ready', '/admin'], true) || str_starts_with($config->get('observability.prometheus_path'), '/admin/') || str_starts_with($config->get('observability.prometheus_path'), $config->get('api.prefix') . '/')) {
            throw new \RuntimeException('Metrics path conflicts with API or health routes.');
        }
    }

    private function routes(ConfigRepository $config): Router
    {
        $router = new Router();
        $router->middleware(static function (Request $request, callable $next) use ($config): Response {
            if (strlen($request->body()) > $config->get('app.max_body_bytes')) {
                throw new HttpException(413, 'payload_too_large', 'Request body exceeds the configured limit.');
            }
            return $next($request);
        });
        $health = new HealthController($this->container->get(HealthCheck::class));
        $router->add('GET', '/', $health);
        $router->add('GET', '/health', $health);
        $router->add('GET', $config->get('api.prefix') . '/health', $health);
        $router->add('GET', '/ready', function (): Response {
            $ok = ($this->config()->get('app.env') !== 'production' || parse_url($this->config()->get('app.url'), PHP_URL_SCHEME) === 'https') && (!$this->config()->get('security.require_admin_totp') || \Cajeer\Logs\Security\TwoFactorManager::keyValid($this->config()->get('security.totp_key'))) && $this->container->get(Diagnostics::class)->ok() && $this->container->get(DatabaseStatus::class)->check()['status'] === 'ok';
            return new JsonResponse(['status' => $ok ? 'ok' : 'down', 'service' => $this->config()->get('app.name'), 'version' => $this->version(), 'scope' => 'database'], $ok ? 200 : 503);
        });
        if ($config->get('observability.prometheus_enabled')) {
            $router->add('GET', $config->get('observability.prometheus_path'), function(Request $r) use ($config): Response {
                $token=$config->get('operations.metrics_token');
                if ($token!=='' && $config->get('app.env')==='production' && !$r->secureTransport) { throw new HttpException(403,'https_required','HTTPS is required for metrics authentication.'); }
                if ($token!=='' && !hash_equals('Bearer '.$token,$r->header('Authorization','')??'')) { throw new HttpException(401,'unauthorized','Metrics authentication required.',['WWW-Authenticate'=>'Bearer']); }
                return new Response($this->container->get(PrometheusExporter::class)->render(),200,['Content-Type'=>'text/plain; version=0.0.4; charset=utf-8']);
            });
        }
        \Cajeer\Logs\Http\AdminPanel::register($router, $this);
        IdentityController::register($router, $this, $config->get('api.prefix'));
        IngestController::register($router, $this, $config->get('api.prefix'));
        LogsController::register($router, $this, $config->get('api.prefix'));
        return $router;
    }

    public function config(): ConfigRepository
    {
        $this->boot();
        return $this->container->get(ConfigRepository::class);
    }

    public function handle(Request $request): Response
    {
        $started=hrtime(true);
        $mode=null; $searchMode=null;
        try {
            $this->boot();
            $mode=\Cajeer\Logs\Observability\IngestMetrics::mode($request->method,$request->path,$this->config()->get('api.prefix'));
            $searchMode=\Cajeer\Logs\Observability\IngestMetrics::searchMode($request->method,$request->path,$this->config()->get('api.prefix'));
            $response = $this->container->get(Router::class)->dispatch($request);
        } catch (\Throwable $error) {
            $response = ErrorHandler::response($error, $request->requestId);
        }
        if ($mode!==null) (new \Cajeer\Logs\Observability\IngestMetrics($this->basePath('storage/cache')))->record($mode,$response,(hrtime(true)-$started)/1e9);
        if ($searchMode!==null) (new \Cajeer\Logs\Observability\IngestMetrics($this->basePath('storage/cache'),'search'))->record($searchMode,$response,(hrtime(true)-$started)/1e9);
        return ErrorHandler::decorate($response, $request->requestId, $request->secureTransport);
    }
}
