<?php
declare(strict_types=1);

use Cajeer\Logs\Http\ErrorHandler;
use Cajeer\Logs\Http\Request;

// Never send PHP warnings or stack traces in an HTTP response.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header_remove('X-Powered-By');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $app = require dirname(__DIR__) . '/core/bootstrap.php';
    $app->boot();
    $request = Request::fromGlobals($app->config()->get('app.max_body_bytes'));
    $response = $app->handle($request);
} catch (Throwable $error) {
    $id = bin2hex(random_bytes(16));
    // Bootstrap itself may fail before registering the autoloader.
    if (!class_exists(ErrorHandler::class)) {
        error_log('CajeerLogs bootstrap failed request_id=' . $id);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-ID: ' . $id);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo json_encode(['error' => 'internal_error', 'message' => 'Internal server error.', 'request_id' => $id]);
        }
        return;
    }
    $response = ErrorHandler::decorate(ErrorHandler::response($error, $id), $id);
}
$response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
