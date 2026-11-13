<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class ErrorHandler
{
    public static function response(\Throwable $error, string $requestId): Response
    {
        if ($error instanceof HttpException && $error->status < 500) {
            return new JsonResponse(['error' => $error->error, 'message' => $error->getMessage(), 'request_id' => $requestId], $error->status, $error->headers);
        }
        // Do not log request bodies, headers, exception messages or traces containing credentials.
        error_log(sprintf('CajeerLogs request_id=%s exception=%s at %s:%d', $requestId, $error::class, basename($error->getFile()), $error->getLine()));
        $status = $error instanceof HttpException ? $error->status : 500;
        if ($status === 503) { return new JsonResponse(['error'=>'service_unavailable','message'=>'Service temporarily unavailable.','request_id'=>$requestId],503); }
        return new JsonResponse(['error' => 'internal_error', 'message' => 'Internal server error.', 'request_id' => $requestId], $status);
    }

    public static function decorate(Response $response, string $requestId): Response
    {
        return $response->withHeader('X-Request-ID', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
}
