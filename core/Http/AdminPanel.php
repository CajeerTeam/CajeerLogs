<?php
declare(strict_types=1);
namespace Cajeer\Logs\Http;

use Cajeer\Logs\Kernel\Application;

final class AdminPanel
{
    public static function register(Router $router, Application $app): void
    {
        $router->add('GET', '/admin', static fn(): Response => new Response('', 308, ['Location'=>'/admin/']));
        $router->add('GET', '/admin/', static function (Request $request) use ($app): Response {
            if ($app->config()->get('app.env') === 'production' && (!$request->secureTransport || parse_url($app->config()->get('app.url'), PHP_URL_SCHEME) !== 'https')) {
                throw new HttpException(403, 'https_required', 'HTTPS is required for the admin panel.');
            }
            $template = file_get_contents($app->basePath('admin/index.html'));
            if ($template === false) { throw new \RuntimeException('Admin panel template unavailable.'); }
            $html = strtr($template, [
                '{{API_PREFIX}}'=>htmlspecialchars($app->config()->get('api.prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{VERSION}}'=>htmlspecialchars($app->version(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ]);
            return new Response($html, 200, [
                'Content-Type'=>'text/html; charset=utf-8',
                'Content-Security-Policy'=>"default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
            ]);
        });
    }
}
