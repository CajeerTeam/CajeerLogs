<?php
declare(strict_types=1);

namespace Cajeer\Logs\Tests\Kernel;

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Http\Router;
use Cajeer\Logs\Kernel\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testVersionAndBoot(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        self::assertSame('0.0.6', $app->version());
        $app->boot();
        $router = $app->container()->get(Router::class);
        $app->boot();
        self::assertSame($router, $app->container()->get(Router::class));
        self::assertSame($app->version(), $app->config()->get('app.version'));
    }

    public function testRuntimeHttpAndErrors(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->boot();
        $app->config()->set('app.env', 'test');
        $response = $app->handle(new Request('GET', '/api/v1/health'));
        self::assertSame(200, $response->status);
        self::assertSame('runtime', json_decode($response->body, true, 16, JSON_THROW_ON_ERROR)['scope']);
        self::assertNotEmpty($response->header('X-Request-ID'));
        self::assertSame(405, $app->handle(new Request('POST', '/health'))->status);
        self::assertSame(401, $app->handle(new Request('GET', '/api/v1/logs', secureTransport: true))->status);
    }

    public function testNestedConfiguration(): void
    {
        $config = new ConfigRepository(['app' => ['name' => 'old']]);
        $config->set('app.name', 'new');
        $config->set('app.nullable', null);
        self::assertSame('new', $config->get('app.name'));
        self::assertTrue($config->has('app.nullable'));
        self::assertFalse($config->has('app.missing'));
    }
}
