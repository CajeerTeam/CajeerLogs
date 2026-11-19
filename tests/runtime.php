<?php
declare(strict_types=1);

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Http\Response;
use Cajeer\Logs\Http\Router;
use Cajeer\Logs\Kernel\ServiceContainer;

$app = require dirname(__DIR__) . '/core/bootstrap.php';
$count = 0;
function check(bool $condition, string $name): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    ++$count;
    echo "PASS $name\n";
}
function throws(callable $action, string $class, ?int $status = null): bool {
    try { $action(); } catch (Throwable $error) {
        return $error instanceof $class && ($status === null || ($error instanceof HttpException && $error->status === $status));
    }
    return false;
}
$app->boot();
check($app->version() === '0.8.2', 'release version');
check($app->config()->get('app.version') === $app->version(), 'config version');
$oldEnv = $app->config()->get('app.env'); $oldUrl = $app->config()->get('app.url');
$app->config()->set('app.env', 'production'); $app->config()->set('app.url', 'https://logs.example.test');
$panel = $app->handle(new Request('GET', '/admin/', secureTransport: true));
check($panel->status === 200 && str_contains($panel->body, 'cajeer-version') && str_contains($panel->header('Content-Security-Policy') ?? '', "script-src 'self'"), 'production admin over trusted HTTPS');
check($app->handle(new Request('GET', '/admin/'))->status === 403, 'production admin fails closed without HTTPS');
$app->config()->set('app.env', $oldEnv); $app->config()->set('app.url', $oldUrl);
$c = new ConfigRepository(['a' => ['b' => 1, 'c' => 2]]);
$c->set('a.b', 3); $c->set('a.d.e', null);
check($c->get('a.b') === 3 && $c->get('a.c') === 2 && $c->has('a.d.e') && !$c->has('a.no'), 'nested config and null');
$tmp = tempnam(sys_get_temp_dir(), 'cajeer-env-');
try {
    file_put_contents($tmp, "# comment\nAPP_NAME=Hello world # comment\nTOKEN='a#b'\nEMPTY=\nESC=\"a\\n\\\"b\"\n");
    $env = Environment::load($tmp);
    check($env === ['APP_NAME' => 'Hello world', 'TOKEN' => 'a#b', 'EMPTY' => '', 'ESC' => "a\n\"b"], 'dotenv parsing');
    file_put_contents($tmp, "A=1\nA=2\n");
    check(throws(fn() => Environment::load($tmp), RuntimeException::class), 'dotenv duplicate rejected');
    file_put_contents($tmp, "A=\"unterminated\n");
    check(throws(fn() => Environment::load($tmp), RuntimeException::class), 'dotenv malformed quote rejected');
} finally { unlink($tmp); }
$container = new ServiceContainer();
$container->singleton('shared', fn() => new stdClass());
$container->bind('transient', fn() => new stdClass());
check($container->get('shared') === $container->get('shared'), 'singleton');
check($container->get('transient') !== $container->get('transient'), 'transient');
$container->singleton('cycle', fn(ServiceContainer $c) => $c->get('cycle'));
check(throws(fn() => $container->get('cycle'), RuntimeException::class), 'circular dependency rejected');
check(throws(fn() => $container->get('missing'), RuntimeException::class), 'missing dependency rejected');
$router = new Router();
$order = [];
$router->middleware(function (Request $r, callable $next) use (&$order): Response { $order[] = 'outer-in'; $response = $next($r); $order[] = 'outer-out'; return $response->withHeader('X-Test', 'yes'); });
$router->middleware(function (Request $r, callable $next) use (&$order): Response { $order[] = 'inner-in'; $response = $next($r); $order[] = 'inner-out'; return $response; });
$router->add('POST', '/events/{id}', function (Request $r) use (&$order): array { $order[] = 'handler'; return ['id' => $r->parameter('id'), 'data' => $r->json()]; });
$router->add('GET', '/events/{id}', fn(Request $r): array => ['id' => $r->parameter('id')]);
$router->add('GET', '/events/latest', fn(): array => ['latest' => true]);
$response = $router->dispatch(new Request('POST', '/events/a%20b', ['Content-Type' => 'application/json'], [], '{"ok":true}'));
check($response->status === 200 && $response->header('x-test') === 'yes' && json_decode($response->body, true) === ['id' => 'a b', 'data' => ['ok' => true]], 'route parameters and JSON body');
check($order === ['outer-in','inner-in','handler','inner-out','outer-out'], 'middleware order');
check(json_decode($router->dispatch('GET', '/events/latest')->body, true) === ['latest' => true], 'literal route precedence');
check(throws(fn() => $router->dispatch('GET', '/events/a%2Fb'), HttpException::class, 400), 'encoded slash rejected');
check(throws(fn() => $router->dispatch('GET', '/events/%FF'), HttpException::class, 400), 'invalid UTF-8 rejected');
check(throws(fn() => $router->dispatch('DELETE', '/events/id'), HttpException::class, 405), 'method rejection');
check($router->dispatch('OPTIONS', '/events/id')->status === 204, 'OPTIONS');
check($router->dispatch('HEAD', '/events/id')->status === 200, 'HEAD fallback');
check(throws(fn() => $router->dispatch('GET', '/missing'), HttpException::class, 404), 'missing route');
check(throws(fn() => new Request('GET', '/bad%ZZ'), HttpException::class, 400), 'malformed path rejected');
check(throws(fn() => (new Request('POST','/', ['Content-Type'=>'application/json'], [], '{'))->json(), HttpException::class, 400), 'invalid JSON');
check(throws(fn() => (new Request('POST','/', ['Content-Type'=>'text/plain'], [], '{}'))->json(), HttpException::class, 415), 'invalid content type');
check(throws(fn() => (new Request('POST','/', ['Content-Type'=>'application/json'], [], 'true'))->json(), HttpException::class, 422), 'invalid JSON shape');
check(throws(fn() => (new Response())->withHeader('X-Test', "a\r\nb"), InvalidArgumentException::class), 'header injection rejected');
$runtime = $app->container()->get(Router::class);
$runtime->add('GET', '/__test_error', fn() => throw new RuntimeException('secret-credential'));
$runtime->add('POST', '/__test_json', fn(Request $r): array => $r->json());
$r = $app->handle(new Request('GET', '/__test_error'));
check($r->status === 500 && !str_contains($r->body, 'secret-credential') && json_decode($r->body, true)['request_id'] === $r->header('X-Request-ID'), 'safe 500 and correlation');
$r = $app->handle(new Request('POST', '/__test_json', ['Content-Type'=>'application/json'], [], '{'));
check($r->status === 400 && json_decode($r->body, true)['error'] === 'invalid_json', 'JSON error through kernel');
$app->config()->set('app.max_body_bytes', 2);
check($app->handle(new Request('POST', '/__test_json', ['Content-Type'=>'application/json'], [], '{} '))->status === 413, 'body limit middleware');
$app->config()->set('app.max_body_bytes', 1048576);
check($app->handle(new Request('GET', '/health'))->status === 200, 'health dispatch');
check($app->handle(new Request('GET', '/api/v1/ingest'))->status === 405, 'ingest only accepts POST');
// Production must never expose metrics without authentication.
$app->config()->set('app.env','test');
check($app->handle(new Request('GET', '/metrics'))->status === 200, 'metrics dispatch');
$app->config()->set('app.env',$oldEnv);
check($app->handle(new Request('GET', '/api/v1/system/report', secureTransport: true))->status === 401, 'private diagnostics require authentication');
check($app->handle(new Request('POST', '/health'))->header('Allow') === 'GET, HEAD, OPTIONS', 'Allow header');
echo "Runtime: $count checks passed\n";
