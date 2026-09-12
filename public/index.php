<?php
declare(strict_types=1);

$app = require dirname(__DIR__) . '/core/bootstrap.php';
$app->boot();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json; charset=utf-8');

if ($path === '/' || $path === '/health' || $path === '/api/v1/health') {
    echo json_encode((new Cajeer\Logs\Api\Controller\HealthController())(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

if ($path === '/metrics') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo (new Cajeer\Logs\Observability\PrometheusExporter())->render();
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found', 'path' => $path, 'method' => $method], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
